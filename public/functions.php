<?php

declare(strict_types=1);

class Formatter {
    public static function formatScore(string $scoreString): int {
        $ranks = ["aaa","s","s+","ss","ss+","sss"];
        $scores = [950000,975000,990000,1000000,1005000,1007500];
        if (substr($scoreString, -1, 1) === "k") {
            return str_replace("k", "000", $scoreString);
        } elseif ($scoreString === "鳥") {
            return 1007500;
        } elseif (($position = array_search(strtolower($scoreString), $ranks)) !== FALSE) {
            return $scores[$position];
        } else {
            return (int)$scoreString;
        }
    }

    public static function rateToInteger(string $rateString): int {
        $parts = explode(".", $rateString, 2);
        $integer = (int)$parts[0];
        $fraction = (int)substr($parts[1] . "00", 0, 2);
        return $integer * 100 + $fraction;
    }

    public static function integerToRateValue(int $rateValue): string {
        return number_format($rateValue / 10, 1);
    }

    public static function integerToRate(int $rate): string {
        return number_format($rate / 100, 2);
    }

    public static function rateValueToInteger(string $rateValueString): int {
        $parts = explode(".", $rateValueString, 2);
        $integer = (int)$parts[0];
        $fraction = (int)substr($parts[1] . "0", 0, 1);
        return $integer * 10 + $fraction;
    }
}

class Calculator {
    public static function getRateValue(int $score, int $rateValue): int {
        $rateValue *= 10;
        if ($score >= 1007500) {
            return 200 + $rateValue;
        } else if ($score >= 1005000) {
            return 150 + ($score - 1005000) / 50 + $rateValue;
        } else if ($score >= 1000000) {
            return 100 + ($score - 1000000) / 100 + $rateValue;
        } else if ($score >= 975000) {
            return ($score - 975000) / 250 + $rateValue;
        } else if ($score >= 950000) {
            return -150 + ($score - 950000) / 250 * 1.5 + $rateValue;
        } elseif ($score >= 925000) {
            return -300 + ($score - 925000) / 250 * 1.5 + $rateValue;
        } elseif ($score >= 900000) {
            return -500 + ($score - 900000) / 125 + $rateValue;
        } else {
            return 0;
        }
    }
}