<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit0b969a2c75cb1dedf5ba9ddb268a98c2
{
    public static $prefixLengthsPsr4 = array (
        'P' => 
        array (
            'PHPMailer\\PHPMailer\\' => 20,
        ),
        'A' => 
        array (
            'App\\' => 4,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'PHPMailer\\PHPMailer\\' => 
        array (
            0 => __DIR__ . '/..' . '/phpmailer/phpmailer/src',
        ),
        'App\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit0b969a2c75cb1dedf5ba9ddb268a98c2::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit0b969a2c75cb1dedf5ba9ddb268a98c2::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit0b969a2c75cb1dedf5ba9ddb268a98c2::$classMap;

        }, null, ClassLoader::class);
    }
}
