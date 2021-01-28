<?php

namespace Fifthgate\TwigMagic;

use Illuminate\Support\ServiceProvider;
use DinhQuocHan\Twig\Facades\Twig;
use Twig\TwigFunction;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use \DirectoryIterator;

class TwigMagicServiceProvider extends ServiceProvider {
	/**
    * Publishes configuration file.
    *
    * @return  void
    */
    public function boot()
    {
    	Twig::addFunction(
            new TwigFunction(
                'svg',
                function ($path) {
                    $cachedValue = Cache::get("inline-images-".$path);
                    if ($cachedValue) {
                        return $cachedValue;
                    }
                    $publicPath = public_path($path);
                    if (file_exists($publicPath)) {
                        $mimeType = File::mimeType($publicPath);
                        if ($mimeType = 'image/svg') {
                            $cutOffSize = config('svgloader.inline_cutoff');
                            if (File::size($publicPath) <= $cutOffSize) {
                                $payload = File::get($publicPath);
                                Cache::set("inline-images-".$path, $payload);
                                return $payload;        
                            } else {
                                return "<img src='{$path}'></img>";
                            }
                        }
                    } else {
                        return '';
                    }
                },
                [
                    'is_safe' => [
                        'html'
                    ]
                ]
            )
        );
        Twig::addFunction(
            new TwigFunction(
                'preload',
                function ($path) {
                    if (file_exists(public_path($path))) {
                        $mimeType = File::mimeType(public_path($path));
                        return "<link rel='preload' href='{$path}' type='{$mimeType}' crossorigin>";
                    }
                },
                [
                    'is_safe' => [
                        'html'
                    ]
                ]
            )
        );
        Twig::addFunction(
            new TwigFunction(
                'preloadDir',
                function ($path, $extension = '*') {
                    $cacheKey = 'compiled-directory-preloads-'.str_replace("/", "_", $path).'-'.$extension;
                    $cachedValue = Cache::get($cacheKey);
                    if ($cachedValue) {
                        //return $cachedValue;
                    }
                    if (file_exists(public_path($path))) {
                        $publicPath = public_path($path);
                        $returnString = '';
                        $dir = new DirectoryIterator($publicPath);
                        foreach ($dir as $fileInfo) {
                            if($fileInfo->isDot()) { continue; }
                            if ( $extension!= '*' && $fileInfo->getExtension() != $extension) {
                                continue;
                            }
                            $mimeType = File::mimeType($fileInfo->getPathName());
       
                            $returnString.="<link rel='preload' href='{$path}/{$fileInfo->getFileName()}' type='{$mimeType}' crossorigin>";
                            $returnString.="\n";
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
            )
        );

    }

    /**
    * Make config publishment optional by merging the config from the package.
    *
    * @return  void
    */
    public function register()
    {
    }
}