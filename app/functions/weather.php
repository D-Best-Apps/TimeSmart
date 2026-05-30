<?php
/**
 * Home-page weather widget (v1 nostalgia feature).
 *
 * Reads the `WeatherZip` setting (US ZIP), geocodes it via zippopotam.us, then
 * pulls a current-conditions + multi-day forecast from Open-Meteo. Both APIs are
 * free and key-less. Results are cached to a temp file for 30 minutes so the
 * 30-second auto-refresh on index.php never hammers the upstream services.
 *
 * getWeatherData($conn) returns null when no ZIP is configured or anything
 * fails, so callers can simply skip rendering the widget.
 */

if (!function_exists('weatherCodeInfo')) {
    /** Map a WMO weather code to [emoji, label]. */
    function weatherCodeInfo($code) {
        $code = (int) $code;
        $map = [
            0  => ['☀️', 'Clear'],
            1  => ['🌤️', 'Mainly clear'],
            2  => ['⛅', 'Partly cloudy'],
            3  => ['☁️', 'Overcast'],
            45 => ['🌫️', 'Fog'],
            48 => ['🌫️', 'Rime fog'],
            51 => ['🌦️', 'Light drizzle'],
            53 => ['🌦️', 'Drizzle'],
            55 => ['🌦️', 'Heavy drizzle'],
            56 => ['🌧️', 'Freezing drizzle'],
            57 => ['🌧️', 'Freezing drizzle'],
            61 => ['🌦️', 'Light rain'],
            63 => ['🌧️', 'Rain'],
            65 => ['🌧️', 'Heavy rain'],
            66 => ['🌧️', 'Freezing rain'],
            67 => ['🌧️', 'Freezing rain'],
            71 => ['🌨️', 'Light snow'],
            73 => ['🌨️', 'Snow'],
            75 => ['❄️', 'Heavy snow'],
            77 => ['🌨️', 'Snow grains'],
            80 => ['🌦️', 'Light showers'],
            81 => ['🌧️', 'Showers'],
            82 => ['⛈️', 'Violent showers'],
            85 => ['🌨️', 'Snow showers'],
            86 => ['❄️', 'Snow showers'],
            95 => ['⛈️', 'Thunderstorm'],
            96 => ['⛈️', 'Thunderstorm + hail'],
            99 => ['⛈️', 'Thunderstorm + hail'],
        ];
        return $map[$code] ?? ['🌡️', 'Unknown'];
    }
}

if (!function_exists('weatherHttpGet')) {
    /** Tiny GET helper returning a decoded JSON array, or null on any failure. */
    function weatherHttpGet($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'D-Best-TimeSmart/2.0',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('getWeatherData')) {
    /**
     * @return array|null  ['place','zip','current'=>['temp','emoji','label'],
     *                      'days'=>[['name','emoji','label','hi','lo'], ...]]
     */
    function getWeatherData($conn) {
        // Read configured ZIP
        $zip = '';
        if ($stmt = $conn->prepare("SELECT SettingValue FROM settings WHERE SettingKey = 'WeatherZip' LIMIT 1")) {
            $stmt->execute();
            $stmt->bind_result($zipVal);
            if ($stmt->fetch()) {
                $zip = trim((string) $zipVal);
            }
            $stmt->close();
        }
        if ($zip === '' || !preg_match('/^\d{5}$/', $zip)) {
            return null;
        }

        // Serve from cache if fresh (30 min)
        $cacheFile = sys_get_temp_dir() . '/timesmart_weather_' . $zip . '.json';
        if (is_readable($cacheFile) && (filemtime($cacheFile) > (time() - 1800))) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // Geocode ZIP -> lat/lon/place
        $geo = weatherHttpGet('https://api.zippopotam.us/us/' . $zip);
        if (!$geo || empty($geo['places'][0])) {
            return null;
        }
        $place   = $geo['places'][0];
        $lat     = $place['latitude'] ?? null;
        $lon     = $place['longitude'] ?? null;
        $cityLbl = trim(($place['place name'] ?? '') . ', ' . ($place['state abbreviation'] ?? ''));
        if ($lat === null || $lon === null) {
            return null;
        }

        // Forecast: current conditions + next few days
        $url = 'https://api.open-meteo.com/v1/forecast'
             . '?latitude=' . urlencode($lat)
             . '&longitude=' . urlencode($lon)
             . '&current=temperature_2m,weather_code'
             . '&daily=weather_code,temperature_2m_max,temperature_2m_min'
             . '&temperature_unit=fahrenheit&timezone=auto&forecast_days=4';
        $fc = weatherHttpGet($url);
        if (!$fc || empty($fc['current']) || empty($fc['daily']['time'])) {
            return null;
        }

        [$curEmoji, $curLabel] = weatherCodeInfo($fc['current']['weather_code'] ?? 0);
        $out = [
            'place'   => $cityLbl !== ',' ? $cityLbl : $zip,
            'zip'     => $zip,
            'current' => [
                'temp'  => (int) round($fc['current']['temperature_2m'] ?? 0),
                'emoji' => $curEmoji,
                'label' => $curLabel,
            ],
            'days' => [],
        ];

        $times = $fc['daily']['time'];
        $codes = $fc['daily']['weather_code'] ?? [];
        $highs = $fc['daily']['temperature_2m_max'] ?? [];
        $lows  = $fc['daily']['temperature_2m_min'] ?? [];
        foreach ($times as $i => $dateStr) {
            [$emoji, $label] = weatherCodeInfo($codes[$i] ?? 0);
            $out['days'][] = [
                'name'  => $i === 0 ? 'Today' : date('D', strtotime($dateStr)),
                'emoji' => $emoji,
                'label' => $label,
                'hi'    => isset($highs[$i]) ? (int) round($highs[$i]) : null,
                'lo'    => isset($lows[$i]) ? (int) round($lows[$i]) : null,
            ];
        }

        @file_put_contents($cacheFile, json_encode($out));
        return $out;
    }
}
