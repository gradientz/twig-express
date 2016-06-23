<?php

define('ROOT_DIR', str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']));
define('REQUEST_PATH', explode('?', $_SERVER['REQUEST_URI'])[0]);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/Mime/Mime.php';


// --------
// Settings
// --------

// Twig config
$twigConfig = [
    'debug' => true,
    'cache' => false,
    'autoescape' => true,
    'strict_variables' => true,
    'charset' => 'utf-8'
];

// Load user config
$userConfig = null;

if (file_exists($configFile = ROOT_DIR . '/twigexpress.json')) {
    $userConfigData = file_get_contents($configFile);
    $userConfig = json_decode($userConfigData, true);
    if ($jsonError = json_last_error()) {
        exitWithErrorPage(404, [
            'title' => json_last_error_msg(),
            'file' => $configFile,
            'message' => 'There was a problem while parsing your JSON config. ' .
                'JSON syntax is rather restrictive, so make sure there’s no syntax error. ' .
                '<a target="_blank" href="http://jsonlint.com/?json=' . rawurlencode($userConfigData) .
                '">Test it online with JSONLint</a>'
        ]);
    }
}

// Merge configs
if (is_array($userConfig)) foreach($twigConfig as $key=>$val) {
    if (array_key_exists($key, $userConfig)) $twigConfig[$key] = $userConfig[$key];
}


// ----------------
// Get request info
// ----------------

$requestPath = REQUEST_PATH;
$baseUrl = '/';

// If we're on Apache in a subfolder, e.g. http://localhost/twigexpress/request-path,
// we need to substract the subfolder from the request path.
if (php_sapi_name() != 'cli-server') {
    $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
    $subFolder = str_replace($docRoot, '', ROOT_DIR);
    if (strlen($subFolder) > 1 && strpos($requestPath, $subFolder) === 0) {
        $requestPath = substr($requestPath, strlen($subFolder));
        $baseUrl = '/' . trim($subFolder, '/') . '/';
    }
}

$fileInfo = getFileInfo( $requestPath, ROOT_DIR );

// Should only happen with the CLI server
if ($fileInfo['file']) {
    if ($fileInfo['type']) header('Content-Type: ' . $fileInfo['type']);
    return readfile(ROOT_DIR . '/' . $fileInfo['file']);
}


// -------------------
// Render the template
// -------------------

else if ($fileInfo['twig']) {
    require_once __DIR__ . '/../lib/Twig/Autoloader.php';
    require_once __DIR__ . '/../lib/LoremIpsum/LoremIpsum.php';
    require_once __DIR__ . '/../lib/Parsedown/Parsedown.php';
    Twig_Autoloader::register();

    $twigEnv = new Twig_Environment(
        new Twig_Loader_Filesystem(ROOT_DIR),
        $twigConfig
    );

    // Enable the 'dump' function
    $twigEnv->addExtension(new Twig_Extension_Debug());

    // Add get, post and cookie info
    $twigEnv->addGlobal('_get', $_GET);
    $twigEnv->addGlobal('_post', $_POST);
    $twigEnv->addGlobal('_cookie', $_COOKIE);
    $twigEnv->addGlobal('_base', $baseUrl);

    // Add the global variables from the config
    if (is_array($userConfig) && array_key_exists('globals', $userConfig) && is_array($userConfig['globals'])) {
        foreach ($userConfig['globals'] as $key => $value){
            $twigEnv->addGlobal($key, $value);
        }
    }

    $loremGenerator = new joshtronic\LoremIpsum();
    /**
     * Twig function for using joshtronic\LoremIpsum, so that we can generate
     * fake latin words, sentences and paragraphs.
     *
     * Syntax for command is:
     *     'number type'   -> returns a string
     *     '[number type]' -> returns an array
     *
     * Available types:
     * - 'words' (synonyms: 'word', 'w')
     * - 'sentences' (synonyms: 'sentence', 's')
     * - 'paragraphs' (synonyms: 'paragraph', 'p')
     *
     * Spaces are optional. Example usage:
     *
     *     {{ lorem('5w') }}
     *     {{ lorem('2 sentences') }}
     *     {% for item in lorem('[10p]') %}
     *       <p>{{ item }}</p>
     *     {% endfor %}
     *
     * @param string $type   Name or shortened name of LoremIpsum method to call
     * @param int    $count  Number of items to generate
     * @return string
     */
    $twigEnv->addFilter(new Twig_SimpleFilter('lorem', function($command='1 word') use ($loremGenerator) {
        if (!preg_match('/^\[?\s*(\d{1,3})\s*([a-z]{1,10})\s*\]?$/', strtolower(trim($command)), $matches)) {
            return '';
        }
        $count = (int) $matches[1];
        $method = 'words';
        switch ($matches[2]) {
            case 'w': case 'word': case 'words':
                $method = 'words'; break;
            case 's': case 'sentence': case 'sentences':
                $method = 'sentences'; break;
            case 'p': case 'paragraph': case 'paragraphs':
                $method = 'paragraphs'; break;
        }
        $method .= strpos($matches[0], '[') === 0 ? 'Array' : '';

        if (method_exists($loremGenerator, $method)) {
            $args = func_get_args();
            array_shift($args);
            return call_user_func_array(
                [$loremGenerator, $method],
                array_merge([$count], $args)
            );
        }
        return '';
    }));

    /**
     * Twig filter that transforms a string with Parsedown
     * Usage:
     *     {{ someText|markdown }}
     *     {{ someText|markdown(inline=true) }}
     *
     * @param string  $text   Markdown text to process
     * @param boolean $inline Do not output paragraph-level tags
     * @return string
     */
    $twigEnv->addFilter(new Twig_SimpleFilter('markdown', function($text, $inline=false) {
        $value = (string) $text;
        if ($inline) return Parsedown::instance()->line($value);
        else return Parsedown::instance()->text($value);
    }));

    try {
        $body = $twigEnv->render( $fileInfo['twig'] );
        if ($fileInfo['type']) header('Content-Type: ' . $fileInfo['type']);
        echo $body;
    }
    catch (Twig_Error $e) {
        renderTwigError($e, ROOT_DIR);
    }
}


// --------------------------------
// Error page for 404 or disallowed
// --------------------------------

else {
    $path = $requestPath;
    if (substr($path, -1) == '/') $path .= 'index.twig';
    exitWithErrorPage(404, [
        'title' => 'File does not exist',
        'message' => 'Could not find: <code> ' . $path . '</code><br>' .
            'Looking in: <code>' . ROOT_DIR . '</code>'
    ]);
}
