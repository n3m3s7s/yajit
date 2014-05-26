<?php

/* tests 
 * http://yajit.test/i/1/80/80/public/foo.jpg
 * 
 *  */

namespace Yajit;

// import the Intervention Image Manager Class
use Intervention\Image\ImageManagerStatic as Image;

global $settings;
define('DOCROOT', rtrim(realpath(dirname(__FILE__)), '/'));
@ini_set("gd.jpeg_ignore_warning", 1);
require_once(DOCROOT . '/config/config.php');
//Utils::log($settings,"SETTINGS");
date_default_timezone_set($settings['server']['timezone']);
define('CACHE', DOCROOT . "/cache");

define('MODE_NONE', 0);
define('MODE_RESIZE', 1);
define('MODE_RESIZE_CROP', 2);
define('MODE_CROP', 3);
define('MODE_FIT', 4);
define('FORMAT_JPG', "jpg");
define('FORMAT_PNG', "png");
define('FORMAT_GIF', "gif");
define('CACHING', $settings['image']['cache']);

class Yajit {

    private $param;
    private $settings;
    private $image_path;
    private $cache_file;
    private $last_modified;
    private $output_format = 'default';

    const TOP_LEFT = 1;
    const TOP_MIDDLE = 2;
    const TOP_RIGHT = 3;
    const MIDDLE_LEFT = 4;
    const CENTER = 5;
    const MIDDLE_RIGHT = 6;
    const BOTTOM_LEFT = 7;
    const BOTTOM_MIDDLE = 8;
    const BOTTOM_RIGHT = 9;

    function __construct() {
        global $settings;
        $param = $this->processParams($_GET['param'], $settings['image']);

        $meta = $cache_file = NULL;
        $image_path = ($param->external === true ? "http://{$param->file}" : WORKSPACE . "/{$param->file}");

        Utils::log($image_path, "IMAGE PATH");

        // If the image is not external check to see when the image was last modified
        if ($param->external !== true) {
            $last_modified = is_file($image_path) ? filemtime($image_path) : null;
            Utils::log($last_modified, "last_modified");
        }// Image is external, check to see that it is a trusted source
        else {
            $rules = file(WORKSPACE . '/jit-image-manipulation/trusted-sites', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $allowed = false;

            $rules = array_map('trim', $rules);

            if (count($rules) > 0)
                foreach ($rules as $rule) {
                    $rule = str_replace(array('http://', 'https://'), NULL, $rule);

                    // Wildcard
                    if ($rule == '*') {
                        $allowed = true;
                        break;
                    }

                    // Wildcard after domain
                    else if (substr($rule, -1) == '*' && strncasecmp($param->file, $rule, strlen($rule) - 1) == 0) {
                        $allowed = true;
                        break;
                    }

                    // Match the start of the rule with file path
                    else if (strncasecmp($rule, $param->file, strlen($rule)) == 0) {
                        $allowed = true;
                        break;
                    }

                    // Match subdomain wildcards
                    else if (substr($rule, 0, 1) == '*' && preg_match("/(" . substr((substr($rule, -1) == '*' ? rtrim($rule, "/*") : $rule), 2) . ")/", $param->file)) {
                        $allowed = true;
                        break;
                    }
                }

            if ($allowed == false) {
                Page::renderStatusCode(Page::HTTP_STATUS_FORBIDDEN);
                exit(sprintf('Error: Connecting to %s is not permitted.', $param->file));
            }

            $last_modified = strtotime(Image::getHttpHeaderFieldValue($image_path, 'Last-Modified'));
        }

// if there is no `$last_modified` value, params should be NULL and headers
// should not be set. Otherwise, set caching headers for the browser.
        if ($last_modified) {
            $last_modified_gmt = gmdate('D, d M Y H:i:s', $last_modified) . ' GMT';
            $etag = md5($last_modified . $image_path);
            Utils::log($last_modified_gmt, "last_modified_gmt");
            header('Last-Modified: ' . $last_modified_gmt);
            header(sprintf('ETag: "%s"', $etag));
            header('Cache-Control: public');
        } else {
            $last_modified_gmt = NULL;
            $etag = NULL;
        }

// Check to see if the requested image needs to be generated or if a 304
// can just be returned to the browser to use it's cached version.
        if (CACHING === true && (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH']))) {
            if ($_SERVER['HTTP_IF_MODIFIED_SINCE'] == $last_modified_gmt || str_replace('"', NULL, stripslashes($_SERVER['HTTP_IF_NONE_MATCH'])) == $etag) {
                Page::renderStatusCode(Page::HTTP_NOT_MODIFIED);
                Utils::log("Returning 304 code");
                exit;
            }
        }

// The 'image_path' may change and point to a cache file, but we will
// still need to know which file is supposed to be processed.
        $original_file = $image_path;

        // If CACHING is enabled, check to see that the cached file is still valid.
        if (CACHING === true) {
            $cache_file = sprintf('%s/%s_%s', CACHE, md5($_REQUEST['param'] . intval($settings['image']['quality']) . filemtime($image_path)), basename($image_path));
            Utils::log($cache_file, "CACHE FILE NAME");
            // Cache has expired or doesn't exist
            /* if (is_file($cache_file) && (filemtime($cache_file) < $last_modified)) {
              unlink($cache_file);
              } else if (is_file($cache_file)) {
              $image_path = $cache_file;
              touch($cache_file);
              $param->mode = MODE_NONE;
              } */
            if (is_file($cache_file)) {
                Utils::log($cache_file, "Reading cache file");
                $image_path = $cache_file;
                $param->mode = MODE_NONE;
            }
        }
        $this->param = $param;
        $this->image_path = $image_path;
        $this->last_modified = $last_modified;
        $this->cache_file = $cache_file;
        $this->settings = $settings;
        //Utils::log($this);
    }

    private function processParams($string, &$image_settings) {
        $param = (object) array(
                    'mode' => 0,
                    'width' => 0,
                    'height' => 0,
                    'position' => 0,
                    'background' => 0,
                    'file' => 0,
                    'external' => false
        );
        Utils::log(DOCROOT . '/config/recipes.php', "READING");
        include_once(DOCROOT . '/config/recipes.php');
        //global $recipes;
        Utils::log($recipes, "RECIPES");
        // check to see if $recipes is even available before even checking if it is an array
        if (!empty($recipes) && is_array($recipes)) {
            foreach ($recipes as $url => $recipe) {
                Utils::log($recipe, "Cycling recipe $url");
                // Is the mode regex? If so, bail early and let not JIT process it.
                if ($recipe['mode'] === 'regex' && preg_match($url, $string)) {
                    // change URL to a "normal" JIT URL
                    $string = preg_replace($url, $recipe['jit-parameter'], $string);
                    $is_regex = true;
                    if (!empty($recipe['quality'])) {
                        $image_settings['quality'] = $recipe['quality'];
                    }
                    break 2;
                }
                // Nope, we're not regex, so make a regex and then check whether we this recipe matches
                // the URL string. If not, continue to the next recipe.
                else if (!preg_match('/^' . $url . '\//i', $string, $matches)) {
                    continue;
                }

                // If we're here, the recipe name matches, so we'll go on to fill out the params
                // Is it an external image?
                $param->external = (bool) $recipe['external'];

                // Path to file
                $param->file = substr($string, strlen($url) + 1);

                // Set output quality
                if (!empty($recipe['quality'])) {
                    $image_settings['quality'] = $recipe['quality'];
                }

                // Specific variables based off mode
                // 0 is ignored (direct display)
                // regex is already handled
                switch ($recipe['mode']) {
                    // Resize
                    case '1':
                    // Resize to fit
                    case '4':
                        $param->mode = (int) $recipe['mode'];
                        $param->width = (int) $recipe['width'];
                        $param->height = (int) $recipe['height'];
                        break;

                    // Resize and crop
                    case '2':
                    // Crop
                    case '3':
                        $param->mode = (int) $recipe['mode'];
                        $param->width = (int) $recipe['width'];
                        $param->height = (int) $recipe['height'];
                        $param->position = (int) $recipe['position'];
                        $param->background = $recipe['background'];
                        break;
                }
                Utils::log($param, "RECIPE");
                return $param;
            }
        }

        // Check if only recipes are allowed.
        // We only have to check if we are using a `regex` recipe
        // because the other recipes already return `$param`.
        if ($image_settings['disable_regular_rules'] == 'yes' && $is_regex != true) {
            Page::renderStatusCode(Page::HTTP_STATUS_NOT_FOUND);
            trigger_error('Error generating image', E_USER_ERROR);
            echo 'Regular JIT rules are disabled and no matching recipe was found.';
            exit;
        }

        // Mode 2: Resize and crop
        // Mode 3: Crop
        if (preg_match_all('/^(2|3)\/([0-9]+)\/([0-9]+)\/([1-9])\/([a-fA-F0-9]{3,6}\/)?(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)) {
            $param->mode = (int) $matches[0][1];
            $param->width = (int) $matches[0][2];
            $param->height = (int) $matches[0][3];
            $param->position = (int) $matches[0][4];
            $param->background = trim($matches[0][5], '/');
            $param->external = (bool) $matches[0][6];
            $param->file = $matches[0][7];
        }

        // Mode 1: Resize
        // Mode 4: Resize to fit
        else if (preg_match_all('/^(1|4)\/([0-9]+)\/([0-9]+)\/(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)) {
            $param->mode = (int) $matches[0][1];
            $param->width = (int) $matches[0][2];
            $param->height = (int) $matches[0][3];
            $param->external = (bool) $matches[0][4];
            $param->file = $matches[0][5];
        }

        // Mode 0: Direct display of image
        elseif (preg_match_all('/^(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)) {
            $param->external = (bool) $matches[0][1];
            $param->file = $matches[0][2];
        }

        Utils::log($param, "PARAM");

        return $param;
    }

    public static function hello() {
        echo "Hello World";
        echo "<br>" . DOCROOT;
        echo "<br>" . WORKSPACE;
        echo "<br>" . DOMAIN;
    }

    function process() {
        $param = $this->param;
        $image_path = $original_file = $this->image_path;
        $settings = $this->settings;
        $image_quality = intval($settings['image']['quality']);

        Utils::log($image_path, "Reading image");
        $img = Image::make($image_path);
        $mime = $img->mime();
        Utils::log($img->getWidth() . " X " . $img->getHeight(), "Dimensions");
        Utils::log($img->mime(), "Mime");
        Utils::log($image_quality, "Quality");

        // Calculate the correct dimensions. If necessary, avoid upscaling the image.
        $src_w = $img->getWidth();
        $src_h = $img->getHeight();
        if ($settings['image']['disable_upscaling'] == 'yes') {
            $dst_w = min($param->width, $src_w);
            $dst_h = min($param->height, $src_h);
        } else {
            $dst_w = $param->width;
            $dst_h = $param->height;
        }



        //$this->output_format = FORMAT_GIF;
        if ($this->output_format != 'default') {
            $img = $img->encode($this->output_format, $image_quality);
        }
        Utils::log($img->mime(), "Mime");
        // If there is no mode for the requested image, just read the image
        // from it's location (which may be external)
        // http://yajit.test/i/0/public/foo.jpg
        if ($param->mode == MODE_NONE) {
            if (
            // If the external file still exists
                    ($param->external && Image::getHttpResponseCode($original_file) != 200)
                    // If the file is local, does it exist and can we read it?
                    || ($param->external === FALSE && (!file_exists($original_file) || !is_readable($original_file)))
            ) {
                // Guess not, return 404.
                Page::renderStatusCode(Page::HTTP_STATUS_NOT_FOUND);
                trigger_error(sprintf('Image <code>%s</code> could not be found.', str_replace(DOCROOT, '', $original_file)), E_USER_ERROR);
                echo sprintf('Image <code>%s</code> could not be found.', str_replace(DOCROOT, '', $original_file));
                exit;
            }
        }

        switch ($param->mode) {
            // http://yajit.test/i/1/80/80/public/foo.jpg
            case MODE_RESIZE:
                // resize image to fixed size
                $img->resize($dst_w, $dst_h);
                break;

            // http://yajit.test/i/4/300/200/public/foo.jpg
            case MODE_FIT:
                // resize image to fit size
                if ($param->height == 0) {
                    $ratio = ($src_h / $src_w);
                    $dst_h = round($dst_w * $ratio);
                } else if ($param->width == 0) {
                    $ratio = ($src_w / $src_h);
                    $dst_w = round($dst_h * $ratio);
                }

                $src_r = ($src_w / $src_h);
                $dst_r = ($dst_w / $dst_h);
                Utils::log("src_w: $src_w src_h: $src_h dst_w:$dst_w dst_h:$dst_h src_r:$src_r dst_r:$dst_r");

                if ($src_h <= $dst_h && $src_w <= $dst_w) {
                    //$image->applyFilter('resize', array($src_w, $src_h));
                    Utils::log("img->resize($dst_w, $dst_h)");
                    $img->resize($dst_w, $dst_h);
                    break;
                }

                if ($src_h >= $dst_h && $src_r <= $dst_r) {
                    //$image->applyFilter('resize', array(NULL, $dst_h));
                    Utils::log("img->resize(null, $dst_h)");
                    $img->resize(NULL, $dst_h, function ($constraint) {
                        $constraint->aspectRatio();
                    });
                }

                if ($src_w >= $dst_w && $src_r >= $dst_r) {
                    //$image->applyFilter('resize', array($dst_w, NULL));
                    Utils::log("img->resize($dst_w, null)");
                    $img->resize($dst_w, NULL, function ($constraint) {
                        $constraint->aspectRatio();
                    });
                }
                break;

            // http://yajit.test/i/2/500/500/2/public/foo.jpg
            case MODE_RESIZE_CROP:
                if ($param->height == 0) {
                    $ratio = ($src_h / $src_w);
                    $dst_h = round($dst_w * $ratio);
                } else if ($param->width == 0) {
                    $ratio = ($src_w / $src_h);
                    $dst_w = round($dst_h * $ratio);
                }

                $src_r = ($src_w / $src_h);
                $dst_r = ($dst_w / $dst_h);

                if ($src_r < $dst_r) {
                    $img->resize($dst_w, NULL, function ($constraint) {
                        $constraint->aspectRatio();
                    });
                } else {
                    $img->resize(NULL, $dst_h, function ($constraint) {
                        $constraint->aspectRatio();
                    });
                }

            // http://yajit.test/i/3/500/500/2/public/foo.jpg
            case MODE_CROP:
                //$image->applyFilter('crop', array($dst_w, $dst_h, $param->position, $param->background));
                $dst_w = $img->getWidth();
                $dst_h = $img->getHeight();
                $src_w = $img->getWidth();
                $src_h = $img->getHeight();
                $width = $param->width;
                $height = $param->height;
                Utils::log("$dst_w, $dst_h, $width, $height");
                if (!empty($width) && !empty($height)) {
                    $dst_w = $width;
                    $dst_h = $height;
                } else if (empty($height)) {
                    $ratio = ($dst_h / $dst_w);
                    $dst_w = $width;
                    $dst_h = round($dst_w * $ratio);
                } else if (empty($width)) {
                    $ratio = ($dst_w / $dst_h);
                    $dst_h = $height;
                    $dst_w = round($dst_h * $ratio);
                }
                Utils::log("$dst_w, $dst_h, $width, $height");

                list($src_x, $src_y, $dst_x, $dst_y) = $this->__calculateDestSrcXY($dst_w, $dst_h, $src_w, $src_h, $src_w, $src_h, $param->position);
                Utils::log("$src_x, $src_y, $dst_x, $dst_y");
                $img->crop($dst_w, $dst_h, $dst_x, $dst_y);
                break;
        }

        if ($param->mode != MODE_NONE AND $img->mime() == "image/jpeg") {
            $img->interlace();
        }

        $output_format = ($this->output_format == "default") ? $img->mime() : $this->output_format;
        switch ($output_format) {
            case "image/jpeg":
                $format = FORMAT_JPG;
                break;
            case "image/png":
                $format = FORMAT_PNG;
                break;
            case "image/gif":
                $format = FORMAT_GIF;
                break;

            default:
                break;
        }

        if (CACHING AND ! file_exists($this->cache_file)) {
            Utils::log($this->cache_file, "SAVING CACHE FILE");
            $img->encode($format, $image_quality);
            $img->save($this->cache_file);
        }

        echo $img->response($format, $image_quality);
        exit;
    }

    private function __calculateDestSrcXY($width, $height, $src_w, $src_h, $dst_w, $dst_h, $position = self::TOP_LEFT) {

        $dst_x = $dst_y = 0;
        $src_x = $src_y = 0;

        if ($width < $src_w) {
            $mx = array(
                0,
                ceil(($src_w * 0.5) - ($width * 0.5)),
                $src_x = $src_w - $width
            );
        } else {
            $mx = array(
                0,
                ceil(($width * 0.5) - ($src_w * 0.5)),
                $src_x = $width - $src_w
            );
        }

        if ($height < $src_h) {
            $my = array(
                0,
                ceil(($src_h * 0.5) - ($height * 0.5)),
                $src_y = $src_h - $height
            );
        } else {

            $my = array(
                0,
                ceil(($height * 0.5) - ($src_h * 0.5)),
                $src_y = $height - $src_h
            );
        }

        switch ($position) {

            case 1:
                break;

            case 2:
                $src_x = 1;
                break;

            case 3:
                $src_x = 2;
                break;

            case 4:
                $src_y = 1;
                break;

            case 5:
                $src_x = 1;
                $src_y = 1;
                break;

            case 6:
                $src_x = 2;
                $src_y = 1;
                break;

            case 7:
                $src_y = 2;
                break;

            case 8:
                $src_x = 1;
                $src_y = 2;
                break;

            case 9:
                $src_x = 2;
                $src_y = 2;
                break;
        }

        $a = ($width >= $dst_w ? $mx[$src_x] : 0);
        $b = ($height >= $dst_h ? $my[$src_y] : 0);
        $c = ($width < $dst_w ? $mx[$src_x] : 0);
        $d = ($height < $dst_h ? $my[$src_y] : 0);

        return array((int) $a, (int) $b, (int) $c, (int) $d);
    }

}
