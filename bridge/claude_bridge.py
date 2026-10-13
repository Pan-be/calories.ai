#!/usr/bin/env python3
"""Kalorie AI bridge: lets the app (PHP in Docker) use Claude on Konrad's own subscription.

Claude Code is logged in on the host, not in the container, so this small service runs
on the host and serves one job over a Unix socket: run `claude -p` with no tools, a
given system prompt, the given content (text and images) and a JSON schema, and return
the structured output. The prompt and schema belong to the app; this side only enforces
limits (size, models, timeout, concurrency) and maps Claude Code's errors.

    python3 claude_bridge.py --socket /opt/kalorie/run/ai.sock

HTTP over the socket (curl --unix-socket):
    GET  /v1/health      -> {"ok": true, "busy": 0, "max_parallel": 2}
    POST /v1/structured  {"model", "system", "content": [...], "schema", "timeout_s"}
                         -> {"ok": true, "output": {...}, "model", "duration_ms", "usage"}
Errors: {"ok": false, "error": {"type", "detail"}} with 400 bad_request, 429 busy,
503 usage_limit, 504 timeout, 502 engine_error.
"""

import argparse
import json
import os
import shutil
import signal
import socketserver
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler

MAX_BODY = 12 * 1024 * 1024  # a few photos at ~1600 px, base64-encoded
MAX_IMAGES = 4
IMAGE_TYPES = {"image/jpeg", "image/png", "image/webp", "image/gif"}
MODELS = {"sonnet", "opus", "haiku"}
DEFAULT_TIMEOUT_S = 90
MAX_TIMEOUT_S = 180
# No tools, no MCP, no settings, hooks, skills or CLAUDE.md from this machine, no session
# files: the model sees only the app's system prompt and content.
FLAGS = [
    "--input-format", "stream-json", "--output-format", "stream-json", "--verbose",
    "--tools", "", "--permission-prompts", "none", "--strict-mcp-config",
    "--setting-sources", "", "--disable-slash-commands", "--no-session-persistence",
]


class BridgeError(Exception):
    def __init__(self, status, kind, detail):
        super().__init__(detail)
        self.status, self.kind, self.detail = status, kind, detail


def log(message):
    print(time.strftime("%Y-%m-%d %H:%M:%S ") + message, flush=True)


def validate(body):
    """The request as (model, system, content, schema, timeout_s), or BridgeError."""
    if not isinstance(body, dict):
        raise BridgeError(400, "bad_request", "body must be a JSON object")
    model = body.get("model", "sonnet")
    if model not in MODELS:
        raise BridgeError(400, "bad_request", f"model must be one of {sorted(MODELS)}")
    system = body.get("system")
    if not isinstance(system, str) or not system.strip():
        raise BridgeError(400, "bad_request", "system must be a non-empty string")
    schema = body.get("schema")
    if not isinstance(schema, dict):
        raise BridgeError(400, "bad_request", "schema must be a JSON object")
    timeout_s = body.get("timeout_s", DEFAULT_TIMEOUT_S)
    if not isinstance(timeout_s, int) or timeout_s < 5:
        raise BridgeError(400, "bad_request", "timeout_s must be an integer >= 5")

    content = body.get("content")
    if not isinstance(content, list) or not content:
        raise BridgeError(400, "bad_request", "content must be a non-empty list")
    blocks, images = [], 0
    for part in content:
        kind = part.get("type") if isinstance(part, dict) else None
        if kind == "text" and isinstance(part.get("text"), str) and part["text"].strip():
            blocks.append({"type": "text", "text": part["text"]})
        elif kind == "image" and part.get("media_type") in IMAGE_TYPES and isinstance(part.get("data"), str):
            images += 1
            blocks.append({"type": "image", "source": {
                "type": "base64", "media_type": part["media_type"], "data": part["data"]}})
        else:
            raise BridgeError(400, "bad_request", "content parts: {type: text, text} or {type: image, media_type, data}")
    if images > MAX_IMAGES:
        raise BridgeError(400, "bad_request", f"at most {MAX_IMAGES} images")
    return model, system, blocks, schema, min(timeout_s, MAX_TIMEOUT_S)


def parse_stream(stdout):
    """(result event, rate limit info) from Claude Code's stream-json output."""
    result, limits = None, None
    for line in stdout.splitlines():
        try:
            event = json.loads(line)
        except json.JSONDecodeError:
            continue
        if not isinstance(event, dict):
            continue
        if event.get("type") == "result":
            result = event
        elif event.get("type") == "rate_limit_event" and isinstance(event.get("rate_limit_info"), dict):
            limits = event["rate_limit_info"]
    return result, limits


def run_claude(claude, model, system, blocks, schema, timeout_s):
    message = {"type": "user", "message": {"role": "user", "content": blocks}}
    argv = [claude, "-p", *FLAGS, "--model", model, "--system-prompt", system, "--json-schema", json.dumps(schema)]
    try:
        # cwd "/" so no project CLAUDE.md is found above the working directory.
        done = subprocess.run(argv, input=json.dumps(message) + "\n", capture_output=True, text=True,
                              timeout=timeout_s, cwd="/")
    except subprocess.TimeoutExpired:
        raise BridgeError(504, "timeout", f"claude did not answer within {timeout_s} s")

    result, limits = parse_stream(done.stdout or "")
    if result is None:
        tail = (done.stderr or "").strip().splitlines()[-1:] or [f"exit code {done.returncode}"]
        raise BridgeError(502, "engine_error", "no result from claude: " + tail[0][:300])
    if result.get("is_error") or result.get("subtype") != "success":
        text = str(result.get("result") or result.get("subtype") or "error")
        rejected = isinstance(limits, dict) and limits.get("status") == "rejected"
        if rejected or "limit" in text.lower():
            raise BridgeError(503, "usage_limit", text[:300])
        raise BridgeError(502, "engine_error", text[:300])

    output = result.get("structured_output")
    if not isinstance(output, dict):
        try:
            output = json.loads(result.get("result") or "")
        except (TypeError, json.JSONDecodeError):
            output = None
    if not isinstance(output, dict):
        raise BridgeError(502, "engine_error", "claude returned no structured output")

    usage = result.get("usage") or {}
    return {
        "output": output,
        "model": next(iter(result.get("modelUsage") or {}), model),
        "usage": {"input_tokens": usage.get("input_tokens"), "output_tokens": usage.get("output_tokens")},
    }


class Handler(BaseHTTPRequestHandler):
    server_version = "KalorieBridge/1"

    def address_string(self):
        return "unix"

    def log_message(self, fmt, *args):
        pass  # one line per request is logged in do_POST instead

    def send_json(self, status, payload):
        data = json.dumps(payload, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path != "/v1/health":
            return self.send_json(404, {"ok": False, "error": {"type": "not_found", "detail": self.path}})
        self.send_json(200, {"ok": True, "busy": self.server.busy, "max_parallel": self.server.max_parallel})

    def do_POST(self):
        if self.path != "/v1/structured":
            return self.send_json(404, {"ok": False, "error": {"type": "not_found", "detail": self.path}})
        started = time.monotonic()
        model, images = "-", 0
        try:
            length = int(self.headers.get("Content-Length") or 0)
            if length <= 0 or length > MAX_BODY:
                raise BridgeError(400, "bad_request", f"body must be 1..{MAX_BODY} bytes")
            try:
                body = json.loads(self.rfile.read(length))
            except (json.JSONDecodeError, UnicodeDecodeError):
                raise BridgeError(400, "bad_request", "body is not valid JSON")
            model, system, blocks, schema, timeout_s = validate(body)
            images = sum(1 for b in blocks if b["type"] == "image")
            if not self.server.slots.acquire(blocking=False):
                raise BridgeError(429, "busy", "too many analyses at once, try again in a moment")
            try:
                self.server.adjust_busy(1)
                answer = run_claude(self.server.claude, model, system, blocks, schema, timeout_s)
            finally:
                self.server.adjust_busy(-1)
                self.server.slots.release()
            duration_ms = int((time.monotonic() - started) * 1000)
            log(f"ok model={answer['model']} images={images} {duration_ms} ms")
            self.send_json(200, {"ok": True, **answer, "duration_ms": duration_ms})
        except BridgeError as e:
            log(f"{e.kind} model={model} images={images}: {e.detail}")
            self.send_json(e.status, {"ok": False, "error": {"type": e.kind, "detail": e.detail}})


class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True

    def __init__(self, path, claude, max_parallel):
        self.claude = claude
        self.max_parallel = max_parallel
        self.slots = threading.BoundedSemaphore(max_parallel)
        self.busy = 0
        self._lock = threading.Lock()
        super().__init__(path, Handler)

    def adjust_busy(self, delta):
        with self._lock:
            self.busy += delta


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--socket", required=True, help="Unix socket path (its directory must exist)")
    parser.add_argument("--max-parallel", type=int, default=2)
    args = parser.parse_args()

    claude = shutil.which("claude")
    if not claude:
        sys.exit("claude executable not found on PATH")
    if os.path.exists(args.socket):
        os.unlink(args.socket)  # left over from a previous run
    old_umask = os.umask(0o177)  # socket 0600: only this user (the app container runs as the same uid)
    server = Server(args.socket, claude, args.max_parallel)
    os.umask(old_umask)
    signal.signal(signal.SIGTERM, lambda *_: threading.Thread(target=server.shutdown).start())
    log(f"listening on {args.socket} (claude: {claude}, max parallel {args.max_parallel})")
    try:
        server.serve_forever()
    finally:
        server.server_close()
        if os.path.exists(args.socket):
            os.unlink(args.socket)
        log("stopped")


if __name__ == "__main__":
    main()
