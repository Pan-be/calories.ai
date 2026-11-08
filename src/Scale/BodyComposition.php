<?php

namespace App\Scale;

use App\Entity\User;

/**
 * Whole-body composition from the scale's 22 impedance values (2026-11-08), computed on read so
 * a better formula recomputes the whole history.
 *
 * Mapping (Kalorie Scale note, step 3): the values are ohms × 10. Four limb blocks of the
 * shape (A1, A2, B1, B2) sit at values 1,2,4,5 / 6,7,9,10 / 11,12,14,15 / 17,18,20,21 (1-based);
 * blocks 1 and 3 are the arms, 2 and 4 the legs (left/right unknown, and not needed here).
 * B is always lower than A, so most likely the higher frequency. Values 3, 8, 13, 16 and 19
 * are in the trunk range (value 22 is cut short by the frame's 03 03 trailer, so it's left out).
 *
 * Whole-body resistance R = mean arm B + mean leg B + median trunk value. Lean mass from
 * Sun et al. 2003 (NHANES III, men): FFM = −10.68 + 0.65·H²/R + 0.26·W + 0.02·R, then the fat %
 * is shifted by a calibration fitted to the scale's own display (8 weighings with photos,
 * 2026-10-28 to 11-05: −8.0 points, every one within 0.75), with Konrad's height of 179 cm
 * (corrected from 169 on 2026-11-08; the scale's own BMI uses 179). The fit holds only at that
 * height: a changed height moves the whole history. Water follows the display's own
 * rule, 73.5 % of lean mass. There is no calibration for women yet, so no result for them.
 */
final readonly class BodyComposition
{
    public const OK = 'ok';
    public const NO_FRAME = 'no_frame';
    public const NO_IMPEDANCE = 'no_impedance';
    public const NO_PROFILE = 'no_profile';
    public const NOT_CALIBRATED = 'not_calibrated';

    private const ARMS = [[0, 1, 3, 4], [10, 11, 13, 14]];
    private const LEGS = [[5, 6, 8, 9], [16, 17, 19, 20]];
    private const TRUNK = [2, 7, 12, 15, 18];
    private const LIMB_OHMS = [150.0, 600.0];
    private const TRUNK_OHMS = [10.0, 60.0];
    private const MALE_OFFSET = -8.0;
    private const WATER_PER_LEAN = 0.735;

    /**
     * @param list<float>|null $impedances the frame's 22 raw values (ohms × 10), null without a frame
     * @return array{status:string, body:array{fat_pct:float, fat_kg:float, lean_kg:float, water_pct:float}|null}
     */
    public static function compute(?array $impedances, float $weightKg, User $user): array
    {
        if ($impedances === null) {
            return self::none(self::NO_FRAME);
        }
        $ohms = array_map(fn (float $v) => $v / 10, $impedances);
        if (!self::plausible($ohms)) {
            return self::none(self::NO_IMPEDANCE);
        }
        $height = $user->getHeightCm();
        if ($user->getSex() === null || $height === null) {
            return self::none(self::NO_PROFILE);
        }
        if ($user->getSex() !== 'male') {
            return self::none(self::NOT_CALIBRATED);
        }

        $r = self::meanB($ohms, self::ARMS) + self::meanB($ohms, self::LEGS) + self::median(array_map(fn ($i) => $ohms[$i], self::TRUNK));
        $lean = -10.68 + 0.65 * $height ** 2 / $r + 0.26 * $weightKg + 0.02 * $r;
        $fatPct = 100 * ($weightKg - $lean) / $weightKg + self::MALE_OFFSET;
        // A fixed offset can push a lean man's estimate below anything real; 3 % is the usual essential-fat floor.
        $fatPct = max(3.0, $fatPct);
        $fatKg = $weightKg * $fatPct / 100;

        return ['status' => self::OK, 'body' => [
            'fat_pct' => round($fatPct, 1),
            'fat_kg' => round($fatKg, 2),
            'lean_kg' => round($weightKg - $fatKg, 2),
            'water_pct' => round(self::WATER_PER_LEAN * (100 - $fatPct), 1),
        ]];
    }

    /** @param list<float> $ohms */
    private static function plausible(array $ohms): bool
    {
        foreach ([...self::ARMS, ...self::LEGS] as $block) {
            foreach ($block as $i) {
                if (!($ohms[$i] >= self::LIMB_OHMS[0] && $ohms[$i] <= self::LIMB_OHMS[1])) {
                    return false;
                }
            }
        }
        foreach (self::TRUNK as $i) {
            if (!($ohms[$i] >= self::TRUNK_OHMS[0] && $ohms[$i] <= self::TRUNK_OHMS[1])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mean of the B pair (the lower, likely higher-frequency values) over both sides.
     *
     * @param list<float> $ohms
     * @param list<list<int>> $blocks
     */
    private static function meanB(array $ohms, array $blocks): float
    {
        $values = [];
        foreach ($blocks as $block) {
            $values[] = $ohms[$block[2]];
            $values[] = $ohms[$block[3]];
        }

        return array_sum($values) / count($values);
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);

        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /** @return array{status:string, body:null} */
    private static function none(string $status): array
    {
        return ['status' => $status, 'body' => null];
    }
}
