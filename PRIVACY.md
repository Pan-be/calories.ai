# Polityka prywatności / Privacy policy

*Wersja z 14 października 2026 / Version of 14 October 2026*

Kalorie to otwartoźródłowa aplikacja, którą każdy może postawić na własnym serwerze.
Ta polityka opisuje, jak działa **kod z tego repozytorium**. Za konkretną instancję
odpowiada jej **operator**: osoba lub firma, która ją uruchomiła i udostępnia (np.
właściciel prywatnego serwera domowego). Autorzy kodu nie mają dostępu do danych
żadnej instancji.

Kalorie is open-source software that anyone can run on their own server. This policy
describes what **the code in this repository** does. Each running instance is the
responsibility of its **operator**: the person or organisation who runs it and gives
others access. The authors of the code have no access to the data of any instance.
[English version below.](#english)

---

## Polski

### Jakie dane są zapisywane

Wszystko trafia do bazy SQLite i katalogu ze zdjęciami na serwerze operatora:

| Dane | Po co |
|---|---|
| E-mail i hasło (tylko skrót: bcrypt/Argon2, nigdy jawny tekst) | logowanie |
| Strefa czasowa | żeby dzień w aplikacji zgadzał się z Twoim zegarem |
| Profil: płeć, data urodzenia, wzrost, poziom aktywności, cel, waga docelowa | wyliczenie dziennego celu kalorii i makro |
| Pomiary wagi | wykres postępów, aktualizacja celu |
| Posiłki: nazwa, godzina, składniki z wartościami, opis lub notatka, którą wpiszesz, wynik analizy AI | dziennik |
| Zdjęcia posiłków (przetworzone, patrz niżej) i miniatury | dziennik |
| Treningi: opis, intensywność lub dzień odpoczynku | kontekst dla podpowiedzi posiłków, później dopasowanie celu |
| Tokeny logowania (tylko skrót SHA-256) i nazwa aplikacji lub przeglądarki, z której się logujesz | utrzymanie sesji |

Dane o wadze, diecie i treningach mogą być danymi o zdrowiu. Traktuj instancję jak
miejsce na wrażliwe informacje.

### Zdjęcia

Każde zdjęcie jest dekodowane i zapisywane od nowa jako JPEG (obrócone prosto, najwyżej
1600 px, plus miniatura 400 px). Dzięki temu **metadane EXIF, w tym położenie GPS, nie są
zapisywane ani wysyłane do AI**. Aplikacja webowa zmniejsza zdjęcie już na telefonie.
Zdjęcie, którego nie udało się rozpoznać jako jedzenia, jest od razu usuwane.

### Co trafia do dostawcy AI

Analiza posiłków i podpowiedzi korzystają z modelu AI. W tym kodzie jest to Claude firmy
Anthropic, przez subskrypcję operatora albo przez API Anthropic. Wysyłane jest tylko to,
co potrzebne do konkretnego zadania:

- **Analiza posiłku:** zdjęcie (po przetworzeniu opisanym wyżej) i/lub opis posiłku, a
  przy poprawce poprzednia analiza i treść poprawki.
- **Podpowiedzi „Co zjeść?”:** dzienne cele, ile już zjedzono, nazwy dzisiejszych
  posiłków, nazwy i wartości posiłków jadanych najczęściej w ostatnich 30 dniach, opis
  dzisiejszego treningu, godzina i notatka, jeśli ją wpiszesz.

**Nie są wysyłane:** e-mail, hasło, data urodzenia, płeć, wzrost, waga ani historia
wagi. Wywołanie AI nie ma dostępu do narzędzi, plików ani internetu; widzi tylko
powyższą treść. Te dane przetwarza Anthropic na swoich zasadach:
<https://www.anthropic.com/legal/privacy>. Operator może zmienić silnik AI (np. na model
lokalny na własnym serwerze; wtedy nic nie wychodzi poza serwer).

### Czego aplikacja nie robi

- Nie ma reklam, analityki ani śledzenia. Strona nie ładuje skryptów, czcionek ani
  obrazków z zewnętrznych serwerów.
- Nie używa ciasteczek. Token logowania jest trzymany w pamięci przeglądarki
  (`localStorage`) na Twoim urządzeniu.
- Nie sprzedaje ani nie udostępnia danych nikomu poza dostawcą AI w zakresie opisanym
  wyżej.

### Logi

Serwer WWW i PHP zapisują standardowe logi techniczne (adres IP, czas, adres żądania,
przeglądarka, błędy). Most do AI zapisuje tylko czas, model, liczbę zdjęć i czas
odpowiedzi, bez treści. Jak długo logi są trzymane, zależy od operatora.

### Jak długo dane są przechowywane

Do czasu usunięcia. Usunięcie posiłku usuwa jego zdjęcie (o ile żadna kopia posiłku go
nie używa). Pomiary wagi i treningi usuwasz w aplikacji. Token logowania wygasa po 90
dniach nieużywania albo po wylogowaniu. Usunięcie konta usuwa wszystkie jego dane
razem ze zdjęciami; w tej wersji robi to operator na Twoją prośbę (samodzielne usuwanie
konta i eksport danych są planowane). Operator może robić kopie zapasowe serwera, a
kopie żyją według jego zasad.

### Twoje prawa

Jeśli instancja podlega RODO, masz prawo do dostępu do danych, ich sprostowania,
usunięcia, ograniczenia przetwarzania, przeniesienia i sprzeciwu, a także do skargi do
organu nadzorczego (w Polsce: Prezes UODO). Z tymi prośbami zwracaj się do operatora
instancji, z której korzystasz.

### Dla operatorów

Uruchamiając instancję dla innych osób, stajesz się administratorem ich danych.
Wskazówki:

- Używaj HTTPS (przykład w README: Caddy).
- Gdy z aplikacji korzysta ktoś poza Tobą, przełącz silnik na API Anthropic (prywatna
  subskrypcja Claude jest do użytku osobistego) i uzupełnij tę politykę o swoje dane
  kontaktowe, podstawę prawną i czas przechowywania kopii zapasowych.
- Trzymaj `APP_SECRET` i bazę danych poza repozytorium; szyfruj kopie zapasowe.

### Zmiany

Zmiany tej polityki są widoczne w historii repozytorium.

---

## English

### What is stored

Everything lives in an SQLite database and a photo directory on the operator's server:

| Data | Why |
|---|---|
| Email and password (hash only: bcrypt/Argon2, never plain text) | sign-in |
| Time zone | so the app's day matches your clock |
| Profile: sex, date of birth, height, activity level, goal, target weight | computing the daily calorie and macro goal |
| Weight entries | progress chart, goal updates |
| Meals: name, time, items with values, the description or note you type, the AI's analysis | the food diary |
| Meal photos (processed, see below) and thumbnails | the food diary |
| Workouts: description, intensity, or a rest day | context for meal suggestions, later goal adjustment |
| Sign-in tokens (SHA-256 hash only) and the name of the app or browser you signed in from | keeping you signed in |

Weight, diet and training data can count as health data. Treat an instance as a place
that holds sensitive information.

### Photos

Every photo is decoded and saved again as a JPEG (upright, at most 1600 px, plus a 400 px
thumbnail). As a result **EXIF metadata, including GPS position, is never stored or sent
to the AI**. The web app also shrinks the photo on the phone first. A photo that turns
out not to show food is deleted right away.

### What goes to the AI provider

Meal analysis and suggestions use an AI model. In this code that's Anthropic's Claude,
through the operator's subscription or the Anthropic API. Only what a task needs is sent:

- **Meal analysis:** the photo (processed as above) and/or the meal description; for a
  correction, the previous analysis and your correction text.
- **"Co zjeść?" suggestions:** daily goals, what you've eaten today, the names of today's
  meals, the names and values of the meals you logged most often in the last 30 days,
  today's training description, the time, and your note if you write one.

**Not sent:** email, password, date of birth, sex, height, weight or weight history. The
AI call has no tools, files or internet access; it sees only the content above.
Anthropic processes it under its own terms: <https://www.anthropic.com/legal/privacy>.
An operator can switch the engine, e.g. to a local model on their own server, in which
case nothing leaves the server.

### What the app doesn't do

- No ads, analytics or tracking. The page loads no scripts, fonts or images from other
  servers.
- No cookies. The sign-in token is kept in your browser's storage (`localStorage`) on
  your device.
- Data is not sold or shared with anyone except the AI provider, as described above.

### Logs

The web server and PHP keep standard technical logs (IP address, time, request path,
browser, errors). The AI bridge logs only the time, model, number of photos and response
time, never content. How long logs are kept is up to the operator.

### How long data is kept

Until it's deleted. Deleting a meal deletes its photo (unless a copy of that meal still
uses it). You can delete weight entries and workouts in the app. A sign-in token expires
after 90 days without use, or when you sign out. Deleting an account removes all its
data, photos included; in this version the operator does that on request (self-service account deletion
and data export are planned). The operator may back up the server; backups follow the
operator's own rules.

### Your rights

If the instance falls under the GDPR, you have the right to access, correct, delete,
restrict, port and object to the processing of your data, and to complain to a
supervisory authority. Send these requests to the operator of the instance you use.

### For operators

If you run an instance for other people, you become the controller of their data. Tips:

- Use HTTPS (the README shows Caddy).
- When anyone besides you uses the app, switch the engine to the Anthropic API (a
  personal Claude subscription is for personal use), and add your contact details, legal
  basis and backup retention to this policy.
- Keep `APP_SECRET` and the database out of the repository; encrypt backups.

### Changes

Changes to this policy are visible in the repository history.
