<?php

declare(strict_types=1);

namespace AdmidioPlugin\EventAttendance\Service;

/** Retrieves published stable and prerelease versions from the public GitHub API. */
final class ReleaseCheckService
{
    private const API_URL = 'https://api.github.com/repos/MightyMCoder/EventAttendanceTracker/releases';

    /**
     * @return array{connected:bool,stable:array{version:string,url:string}|null,beta:array{version:string,url:string}|null}
     */
    public function getReleasedVersions(): array
    {
        $response = $this->request(self::API_URL);
        if ($response === null) {
            return array('connected' => false, 'stable' => null, 'beta' => null);
        }

        $releases = json_decode($response, true);
        if (!is_array($releases) || !array_is_list($releases)) {
            return array('connected' => false, 'stable' => null, 'beta' => null);
        }

        $stable = null;
        $beta = null;
        foreach ($releases as $release) {
            if (!is_array($release) || !isset($release['tag_name'], $release['html_url']) || !is_string($release['tag_name']) || !is_string($release['html_url'])
                || !str_starts_with($release['html_url'], 'https://github.com/MightyMCoder/EventAttendanceTracker/')) {
                continue;
            }

            $version = ltrim($release['tag_name'], 'v');
            if ($version === '') {
                continue;
            }
            $entry = array('version' => $version, 'url' => $release['html_url']);
            if (($release['prerelease'] ?? false) === true && $beta === null) {
                $beta = $entry;
            } elseif (($release['prerelease'] ?? false) !== true && $stable === null) {
                $stable = $entry;
            }
        }

        return array('connected' => true, 'stable' => $stable, 'beta' => $beta);
    }

    private function request(string $url): ?string
    {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => "User-Agent: EventAttendanceTracker\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 5,
                'ignore_errors' => true,
            )
        ));
        $response = @file_get_contents($url, false, $context);

        return is_string($response) ? $response : null;
    }
}
