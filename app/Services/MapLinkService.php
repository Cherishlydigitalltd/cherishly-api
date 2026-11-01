<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Turns a Google Maps link a host pasted ("Share → Copy link") into the pin's
 * coordinates, so the event page can show the exact spot. Written addresses
 * in Nigeria are often vague; a dropped pin takes guests to the right gate.
 */
class MapLinkService
{
    /** Only Google's own hosts are ever fetched, so this can't be pointed elsewhere. */
    private const HOSTS = [
        'maps.app.goo.gl', 'goo.gl', 'google.com', 'www.google.com',
        'maps.google.com', 'google.com.ng', 'www.google.com.ng', 'maps.google.com.ng',
        'consent.google.com',
    ];

    public function isGoogleMapsLink(string $url): bool
    {
        $parts = parse_url(trim($url));
        return ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true);
    }

    /** @return array{0: float, 1: float}|null [lat, lng] */
    public function coordinates(string $url): ?array
    {
        $url = trim($url);
        if (!$this->isGoogleMapsLink($url)) {
            return null;
        }

        if ($found = $this->parse($url)) {
            return $found;
        }

        // Short links (maps.app.goo.gl/…) only reveal the spot after their
        // redirects, so follow them, staying on Google throughout.
        try {
            $final = $url;
            Http::timeout(4)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 5,
                        'protocols' => ['https'],
                        'on_redirect' => function (RequestInterface $req, $res, UriInterface $to) use (&$final) {
                            if (!in_array(strtolower($to->getHost()), self::HOSTS, true)) {
                                throw new \RuntimeException('Left Google');
                            }
                            $final = (string) $to;
                        },
                    ],
                ])
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; CherishlyBot/1.0)'])
                ->get($url);
            return $this->parse(urldecode($final));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: float, 1: float}|null */
    private function parse(string $url): ?array
    {
        $url = urldecode($url);
        // The place itself (!3d…!4d…) is more precise than the map's centre (@…).
        foreach ([
            '/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/',
            '/[?&](?:q|query|ll|center|destination)=(-?\d{1,2}\.\d+),\s*(-?\d{1,3}\.\d+)/',
            '/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/',
        ] as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
                if (abs($lat) <= 90 && abs($lng) <= 180) {
                    return [round($lat, 7), round($lng, 7)];
                }
            }
        }
        return null;
    }
}
