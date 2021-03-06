<?php

/**
 * Data Pool Module
 *
 * Author Jerry Shaw <jerry-shaw@live.com>
 * Author 秋水之冰 <27206617@qq.com>
 *
 * Copyright 2017 Jerry Shaw
 * Copyright 2017 秋水之冰
 *
 * This file is part of NervSys.
 *
 * NervSys is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * NervSys is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with NervSys. If not, see <http://www.gnu.org/licenses/>.
 */

namespace core\ctrl;

class pool
{
    //Data package
    public static $data = [];

    //Result data pool
    public static $pool = [];

    //Result data format (json/raw)
    public static $format = 'json';

    //Module list
    private static $module = [];

    //Method list
    private static $method = [];

    //Keymap list
    private static $keymap = [];

    //Data Structure
    private static $struct = [];

    /**
     * Initial Data Module
     * Only static methods are supported
     */
    public static function start()
    {
        //Get date from HTTP Request in CGI Mode
        if ('cli' !== PHP_SAPI) self::$data = ENABLE_GET ? $_REQUEST : $_POST;
        //Set result data format
        if (isset(self::$data['format']) && in_array(self::$data['format'], ['json', 'raw'], true)) self::$format = self::$data['format'];
        //Parse "cmd" data
        if (isset(self::$data['cmd']) && is_string(self::$data['cmd']) && false !== strpos(self::$data['cmd'], '\\')) self::parse_cmd(self::$data['cmd']);
        //Parse "map" data
        if (isset(self::$data['map']) && is_string(self::$data['map']) && false !== strpos(self::$data['map'], '\\') && false !== strpos(self::$data['map'], ':')) self::parse_map(self::$data['map']);
        //Unset "format" & "cmd" & "map" from data package
        unset(self::$data['format'], self::$data['cmd'], self::$data['map']);
        //Merge "$_FILES" into data pool if exists
        if (!empty($_FILES)) self::$data = array_merge(self::$data, $_FILES);
        //Continue running if requested data is ready
        if (!empty(self::$module) && (!empty(self::$method) || !empty(self::$data))) {
            //Build data structure
            self::$struct = array_keys(self::$data);
            //Parse Module & Method list
            foreach (self::$module as $module => $libraries) {
                //Load Module CFG file for the first time
                $file = realpath(ROOT . '/' . $module . '/_inc/cfg.php');
                if (false !== $file) require $file;
                //Call Libraries
                foreach ($libraries as $library) {
                    //Point to root class
                    $class = '\\' !== substr($library, 0, 1) ? '\\' . $library : $library;
                    //Check the load status
                    if (class_exists($class, true)) {
                        //Get method list from the library
                        $method_list = get_class_methods($class);
                        //Security Checking
                        if (SECURE_API) {
                            //Checking API Safe Zone
                            $api_list = isset($class::$api) && is_array($class::$api) ? array_keys($class::$api) : [];
                            //Get api methods according to requested methods or all methods will be stored in the intersect list if no method is provided
                            $method_api = !empty(self::$method) ? array_intersect(self::$method, $api_list, $method_list) : array_intersect($api_list, $method_list);
                            //Calling "init" method at the first place if exists without API permission and data structure comparison
                            if (in_array('init', $method_list, true) && !in_array('init', $method_api, true)) self::call_method($library, $class, 'init');
                            //Go through every method in the api list with API Safe Zone checking
                            foreach ($method_api as $method) {
                                //Get the intersect list of the data requirement structure
                                $intersect = array_intersect(self::$struct, $class::$api[$method]);
                                //Get the different list of the data requirement structure
                                $difference = array_diff($class::$api[$method], $intersect);
                                //Calling the api method if the data structure is matched
                                if (empty($difference)) self::call_method($library, $class, $method);
                            }
                        } else if (!empty(self::$method)) {
                            //Requested methods is needed when API Safe Zone checking is turned off
                            $method_api = array_intersect(self::$method, $method_list);
                            //Calling "init" method at the first place if exists without API permission and data structure comparison
                            if (in_array('init', $method_list, true) && !in_array('init', $method_api, true)) self::call_method($library, $class, 'init');
                            //Calling the api method without API Safe Zone checking
                            foreach ($method_api as $method) self::call_method($library, $class, $method);
                        }
                    }
                }
            }
            unset($module, $libraries, $file, $library, $class, $method_list, $api_list, $method_api, $method, $intersect, $difference);
        }
    }

    /**
     * Get Method list
     *
     * @param string $data
     *
     * @return array
     */
    private static function get_list(string $data): array
    {
        if (false !== strpos($data, ',')) {
            //Spilt data when multiple modules/methods exist with ","
            $result = explode(',', $data);
            $result = array_filter($result);
            $result = array_unique($result);
        } else $result = [$data];
        unset($data);
        return $result;
    }

    /**
     * Get module name
     *
     * @param string $module
     * @param int $offset
     *
     * @return string
     */
    private static function get_module(string $module, int $offset): string
    {
        switch ($offset <=> 0) {
            case 1:
                $result = substr($module, 0, $offset);
                break;
            case 0:
                $offset = strpos($module, '\\', 1);
                $result = false !== $offset ? self::get_module(substr($module, 1), --$offset) : '';
                break;
            default:
                $result = '';
                break;
        }
        unset($module, $offset);
        return $result;
    }

    /**
     * "cmd" value parser
     *
     * @param string $data
     */
    private static function parse_cmd(string $data)
    {
        //Extract "cmd" values
        $cmd = self::get_list($data);
        //Parse "cmd" values
        foreach ($cmd as $item) {
            //Detect module and method
            $offset = strpos($item, '\\');
            if (false !== $offset) {
                //Module goes here
                //Get module name
                $module = self::get_module($item, $offset);
                //Make sure the parsed results are available
                if ('' !== $module && false !== substr($item, $offset + 1)) {
                    //Add module to "self::$module" if not added
                    if (!isset(self::$module[$module])) self::$module[$module] = [];
                    //Add library to "self::$module" if not added
                    if (!in_array($item, self::$module[$module], true)) self::$module[$module][] = $item;
                }
            } else {
                //Method goes here
                //Add to "self::$method" if not added
                if (!in_array($item, self::$method, true)) self::$method[] = $item;
            }
        }
        unset($data, $cmd, $item, $offset, $module);
    }

    /**
     * "map" value parser
     *
     * @param string $data
     */
    private static function parse_map(string $data)
    {
        //Extract "map" values
        $map = self::get_list($data);
        //Deeply parse map values
        foreach ($map as $value) {
            //Every map value should contain both "\" and ":"
            $position = strpos($value, ':');
            if (false !== $position && false !== strpos($value, '\\')) {
                //Extract and get map "from" and "to"
                $map_from = substr($value, 0, $position);
                $map_to = substr($value, $position + 1);
                //Deeply parse map "from"
                $offset = strpos($map_from, '\\');
                if (false !== $offset) {
                    //Get module name
                    $module = self::get_module($map_from, $offset);
                    //Module Key exists
                    if ('' !== $module && isset(self::$module[$module])) {
                        $depth = [];
                        //Get map keys
                        $keys = explode('\\', $map_from);
                        //Find the deepest condition
                        do {
                            $library = implode('\\', $keys);
                            //Save library existed under the same Module
                            if (in_array($library, self::$module[$module], true)) {
                                //Save final method to keymap list with popped keys as mapping depth
                                self::$keymap[$library . '\\' . array_pop($depth)] = ['from' => array_reverse($depth), 'to' => &$map_to];
                                break;
                            } else $depth[] = array_pop($keys);
                        } while (!empty($keys));
                        unset($depth, $keys, $library);
                    }
                }
            }
        }
        unset($data, $map, $value, $position, $map_from, $map_to, $offset, $module);
    }

    /**
     * Call method and store the result
     *
     * @param string $library
     * @param string $class
     * @param string $method
     */
    private static function call_method(string $library, string $class, string $method)
    {
        //Get a reflection object for the class method
        $reflect = new \ReflectionMethod($class, $method);
        //Check the visibility and property of the method
        if ($reflect->isPublic() && $reflect->isStatic()) {
            //Get item key
            $item = $library . '\\' . $method;
            //Try to call the method and catch the Exceptions or Errors
            try {
                //Calling method
                $result = $class::$method();
                //Merge result
                if (isset($result)) {
                    //Save result to the result data pool with original library name
                    self::$pool[$item] = &$result;
                    //Check keymap with result data
                    if (isset(self::$keymap[$item])) {
                        //Processing array result to get the final data
                        if (!empty(self::$keymap[$item]['from']) && is_array($result)) {
                            //Check every key in keymap for deeply mapping
                            foreach (self::$keymap[$item]['from'] as $key) {
                                //Check key's existence
                                if (isset($result[$key])) {
                                    //Switch result data to where we find
                                    unset($tmp);
                                    $tmp = $result[$key];
                                    unset($result);
                                    $result = $tmp;
                                } else {
                                    //Unset result data if requested key does not exist
                                    unset($result);
                                    break;
                                }
                            }
                            unset($key, $tmp);
                        }
                        //Map result data to request data if isset
                        if (isset($result)) {
                            //Caution: The data with the same key in data pool will be overwritten if exists
                            self::$data[self::$keymap[$item]['to']] = &$result;
                            //Rebuild data structure
                            self::$struct = array_keys(self::$data);
                        }
                    }
                }
                unset($result);
            } catch (\Throwable $exception) {
                //Save the Exception or Error Message to the result data pool instead
                self::$pool[$item] = $exception->getMessage();
            }
            unset($item);
        }
        unset($library, $class, $method, $reflect);
    }
}
