<?php

namespace Fleetbase\Solid\Support;

use Fleetbase\Support\Utils as FleetbaseUtils;
use Illuminate\Support\Str;

class Utils extends FleetbaseUtils
{
    /**
     * Recursively searches through a nested array of pods and their contents
     * to find an item that matches a given key-value pair.
     *
     * This function handles nested structures where each folder may contain
     * additional folders and files. It returns the first matching item based
     * on the specified key and value.
     *
     * @param array  $data   the array representing the pods and their nested contents
     * @param string $key    The key used to search items (e.g., 'name', 'id').
     * @param string $value  the value to match against the specified key
     * @param bool   $search whether to perform a search match with string contains
     *
     * @return mixed|null returns the first matching item found in the structure or
     *                    `null` if no item is found that matches the criteria
     */
    public static function searchPods(array $data = [], string $key, string $value, bool $search = false)
    {
        foreach ($data as $item) {
            if ($search === false && data_get($item, $key) && strcasecmp(data_get($item, $key), $value) === 0) {
                return $item;
            }

            if ($search === true && data_get($item, $key) && Str::contains(strtolower(data_get($item, $key)), strtolower($value))) {
                return $item;
            }

            if (is_array(data_get($item, 'contents'))) {
                $contentSearchResult = static::searchPods(data_get($item, 'contents', []), $key, $value);
                if ($contentSearchResult) {
                    return $contentSearchResult;
                }
            }
        }

        return null;
    }

    /**
     * Get the Solid server URL from configuration.
     *
     * Constructs the URL from individual server configuration components.
     *
     * @return string
     */
    public static function getSolidServerUrl(): string
    {
        $host = config('solid.server.host', 'http://localhost');
        $port = config('solid.server.port', 3000);
        $secure = config('solid.server.secure', false);
        
        // Remove protocol from host if present
        $host = preg_replace('#^.*://#', '', $host);
        
        // Construct URL with proper protocol
        $protocol = $secure ? 'https' : 'http';
        
        return "{$protocol}://{$host}:{$port}";
    }
}
