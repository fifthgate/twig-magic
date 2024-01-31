<?php

declare(strict_types=1);

namespace Fifthgate\TwigMagic;

use Illuminate\Support\ServiceProvider;

use Twig\TwigFunction;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use \DirectoryIterator;
use \Exception;

class TwigMagicServiceProvider extends ServiceProvider
{

    private bool $testMode;

    private function pathToCacheKey(string $assetType, string $path) : string
    {
        $sanitizedPath = str_replace("/", "_", $path);
        $sanitizedPath = str_replace(".", "_", $sanitizedPath);
        return "inline-{$assetType}-{$sanitizedPath}";
    }

    private function preloadDir(): TwigFunction
    {
        return new TwigFunction(
            'preloadDir',
            function ($path, $extension = '*') {
                $cacheKey = 'compiled-directory-preloads-'.str_replace("/", "_", $path).'-'.$extension;
                $cachedValue = Cache::get($cacheKey);

                $testMode = config('twig-magic.test-mode');
                if ($cachedValue && !$testMode) {
                    return $cachedValue;
                }
                if (file_exists(public_path($path))) {
                    $publicPath = public_path($path);
                    $returnString = '';
                    $dir = new DirectoryIterator($publicPath);
                    foreach ($dir as $fileInfo) {
                        if ($fileInfo->isDot()) {
                            continue;
                        }
                        if ($extension!= '*' && $fileInfo->getExtension() != $extension) {
                            continue;
                        }
                        $mimeType = File::mimeType($fileInfo->getPathName());

                        $returnString.=sprintf(
                            "<link rel='preload' href='/%s/%s' type='%s' crossorigin>\n",
                            $path,
                            $fileInfo->getFileName(),
                            $mimeType
                        );

                    }
                    Cache::set($cacheKey, $returnString);
                    return $returnString;
                }
            },
            [
                'is_safe' => [
                    'html'
                ]
            ]
        );
    }
    private function renderSVG(): TwigFunction
    {
        return new TwigFunction(
            'renderSvg',
            function (string $path, bool $forceInlining = false, array $classes = []
        ): ?string {

                $cacheKey = $this->pathToCacheKey('image', $path);
                $cachedValue = Cache::get($cacheKey);
                $testMode = config('twig-magic.test-mode');

                if ($cachedValue && !$testMode) {
                    return $cachedValue;
                }

                $publicPath = public_path($path);
                if (file_exists($publicPath)) {
                    $mimeType = File::mimeType($publicPath);
                    if ($mimeType = 'image/svg') {
                        $cutOffSize = config('twig-magic.svg_inline_cutoff');
                        if (File::size($publicPath) <= $cutOffSize or $forceInlining) {
                            $payload = File::get($publicPath);
                            if (!empty($classes)) {
                                $classString = implode(" ", $classes);
                                $payload = "<div class='svgwrapper {$classString}'>{$payload}</div>";
                            }
                            Cache::set($cacheKey, $payload);

                            return $payload;
                        } else {
                            if (empty($classes)) {
                                return sprintf('<img src="%s">', $path);
                            } else {

                                return sprintf(
                                    '<img src="%s" class="%s">',
                                    $path,
                                    implode(" ", $classes)
                                );
                            }
                        }
                    }
                }
                return null;
            },
            [
                'is_safe' => [
                    'html'
                ]
            ]
        );
    }

    private function inlineCSS(): TwigFunction
    {
        return new TwigFunction(
            'inlineCss',
            function (string $path, bool $forceInlining = false, string $media = 'screen') {

                $cacheKey = $this->pathToCacheKey('css', $path);

                $cachedValue = Cache::get($cacheKey);
                $testMode = config('twig-magic.test-mode');
                $debug = config('twig-magic.debug', false);
                if ($cachedValue && ! $testMode) {
                    return $cachedValue;
                }
                $absolutePath = public_path($path);

                if (file_exists($absolutePath)) {
                    $extension = File::extension($absolutePath);
                    if ($extension == 'css') {
                        $cutOffSize = config('twig-magic.css_inline_cutoff');

                        if (File::size($absolutePath) <= $cutOffSize or $forceInlining) {
                            $payload = $debug ? "<!-- {$path} Inlined by TwigMagic -->" : "";
                            $payload .= sprintf('<style>%s</style>', File::get($absolutePath));

                            $payload .= $debug ? "<!-- End {$path} Inlining -->" : "";
                        } else {
                            $payload = $debug ? "<!-- {$path} Too big for TwigMagic to inline -->" : "";
                            $payload .= sprintf("<link rel='stylesheet' media='%s' href='/%s'>", $media, $path);

                        }
                        Cache::set($cacheKey, $payload);
                        return $payload;
                    }
                } else {
                    throw new Exception("{$path} not found");
                }
                return null;
            },
            [
                'is_safe' => [
                    'html'
                ]
            ]
        );
    }

    private function inlineCssMultiple(): TwigFunction
    {
        return new TwigFunction(
            'inlineCssMultiple',
            function (array $paths, bool $forceInlining = false, string $media = 'screen') {

                $pathHash = md5(serialize($paths));
                $cacheKey = $this->pathToCacheKey('css-multi', $pathHash);

                $cachedValue = Cache::get($cacheKey);
                $testMode = config('twig-magic.test-mode');
                $debug = config('twig-magic.debug', false);
                if ($cachedValue && ! $testMode) {
                    return $cachedValue;
                }
                $payload = $debug ? "<!-- Inlined by TwigMagic -->" : "";
                $payload.="<style>";

                //Simple container for paths that are too large to inline.
                $chunkyPaths = [];


                foreach ($paths as $path) {
                    $absolutePath = public_path($path);
                    if (file_exists($absolutePath)) {
                        $cutOffSize = config('twig-magic.css_inline_cutoff');

                        if (File::size($absolutePath) <= $cutOffSize or $forceInlining) {
                            $payload .= File::get($absolutePath);
                        } else {
                            $chunkyPaths[] = $path;
                        }
                    } else {
                        throw new Exception("{$path} not found");
                    }
                }
                $payload .= '</style>';
                if (!empty($chunkyPaths)) {
                    foreach ($chunkyPaths as $chunkyPath) {
                        $payload .= $debug ? "<!-- {$path} Too big for TwigMagic to inline -->" : "";
                        $payload .= "<link rel='stylesheet' media='{$media}' href='/{$path}'>";
                    }
                }
                Cache::set($cacheKey, $payload);
                return $payload;
            },
            [
                'is_safe' => [
                    'html'
                ]
            ]
        );
    }

    private function inlineJS(): TwigFunction
    {
        return new TwigFunction(
            'inlineJs',
            function (
                string $path,
                bool $forceInlining = false,
                string $media = 'screen',
                bool $async = true,
                bool $defer = true
            ) {
                $cacheKey = $this->pathToCacheKey('js', $path);
                $cachedValue = Cache::get($cacheKey);
                $testMode = config('twig-magic.test-mode');
                if ($cachedValue && !$testMode) {
                    return $cachedValue;
                }
                $publicPath = public_path($path);

                if (file_exists($publicPath)) {
                    $extension = File::extension($publicPath);
                    
                    if ($extension == 'js') {
                        $cutOffSize = config('twig-magic.js_inline_cutoff');
                        if (File::size($publicPath) <= $cutOffSize or $forceInlining) {
                            $debug = config('twig-magic.debug', false);
                            $fileContent = File::get($publicPath);
                            $payload = $debug ? "<!-- {$path} Inlined by TwigMagic -->" : "";
                            $payload .= "<script";
                            if ($async) {
                                $payload.=' async';
                            }
                            if ($defer) {
                                $payload.=' defer';
                            }
                            $payload.='>';
                            
                            $payload.=$fileContent;
                            $payload.='</script>';
                            $payload .= $debug ? "<!-- End {$path} Inlining -->" : "";
                        } else {
                            $payload = "<script";
                            if ($async) {
                                $payload.=' async';
                            }
                            if ($defer) {
                                $payload.=' defer';
                            }
                            $payload.=" src='/{$publicPath}'";
                            $payload.='>';
                            
                            
                            $payload.='</script>';
                        }
                        Cache::set($cacheKey, $payload);
                        return $payload;
                    }
                }
                return null;
            },
            [
                'is_safe' => [
                    'html'
                ]
            ]
        );
    }

    private function preloadAsset(): TwigFunction
    {
        return new TwigFunction(
            'preloadAsset',
            function ($path) {
                $publicPath = public_path($path);
                if (file_exists($publicPath)) {
                    $mimeType = File::mimeType($publicPath);
                    $extension = File::extension($publicPath);
                    if ($extension == 'css') {
                        return "<link rel='preload' href='/{$path}' as='style'>";
                    }
                    return "<link rel='preload' href='/{$path}' type='{$mimeType}' crossorigin>";
                }
            },
            [
                'is_safe' => [
                    'html'
                ]
            ]
        );
    }
    /**
    * Publishes configuration file.
    *
    * @return  void
    */
    public function boot()
    {

        $this->testMode = config('twig-magic.test-mode') ?? false;
        $this->publishes(
            [
                __DIR__.'/../config/twig-magic.php' => config_path('twig-magic.php'),
            ],
            'twig-magic'
        );
    }

    /**
    * Make config publishment optional by merging the config from the package.
    *
    * @return  void
    */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/twig-magic.php',
            'twig-magic.php/'
        );
    }

    private function getFunctions()
    {
        return [
            'renderSVG' => $this->renderSVG(),
            'inlineCss' => $this->inlineCSS(),
            'inlineCssMultiple' => $this->inlineCssMultiple(),
            'preloadAsset' => $this->preloadAsset(),
            'preloadDir' => $this->preloadDir(),
            'inlineJS' => $this->inlineJS(),

        ];
    }
}
