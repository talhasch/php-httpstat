#!/usr/bin/php
<?php
// PHP implementation of reorx's httpstat https://github.com/reorx/httpstat

error_reporting(E_ALL);

$__version__ = '0.1.0';


class Env
{
    private $prefix = 'HTTPSTAT';

    public $key = null;

    public function __construct($key)
    {
        $this->key = str_replace('{prefix}', $this->prefix, $key);
    }

    public function get($default = null)
    {
        if (strlen(getenv($this->key)) === 0) {
            return $default;
        }

        return getenv($this->key);
    }
}

$ENV_SHOW_BODY = new Env('{prefix}_SHOW_BODY');
$ENV_SHOW_SPEED = new Env('{prefix}_SHOW_SPEED');
$ENV_SAVE_BODY = new Env('{prefix}_SAVE_BODY');
$ENV_CURL_BIN = new Env('{prefix}_CURL_BIN');
$ENV_DEBUG = new Env('{prefix}_DEBUG');


$curlFormat = '{' .
    '"time_namelookup": %{time_namelookup},' .
    '"time_connect": %{time_connect},' .
    '"time_appconnect": %{time_appconnect},' .
    '"time_pretransfer": %{time_pretransfer},' .
    '"time_redirect": %{time_redirect},' .
    '"time_starttransfer": %{time_starttransfer},' .
    '"time_total": %{time_total},' .
    '"speed_download": %{speed_download},' .
    '"speed_upload": %{speed_upload}' .
    '}';


$httpsTemplate = "  DNS Lookup   TCP Connection   TLS Handshake   Server Processing   Content Transfer
[   {a0000}  |     {a0001}    |    {a0002}    |      {a0003}      |      {a0004}     ]
             |                |               |                   |                  |
    namelookup:{b0000}        |               |                   |                  |
                        connect:{b0001}       |                   |                  |
                                    pretransfer:{b0002}           |                  |
                                                      starttransfer:{b0003}          |
                                                                                 total:{b0004}
";

$httpTemplate = "  DNS Lookup   TCP Connection   Server Processing   Content Transfer
[   {a0000}  |     {a0001}    |      {a0003}      |      {a0004}     ]
             |                |                   |                  |
    namelookup:{b0000}        |                   |                  |
                        connect:{b0001}           |                  |
                                      starttransfer:{b0003}          |
                                                                 total:{b0004}
";


$ISATTY = function_exists('posix_isatty') ? posix_isatty(STDOUT) : false;

function makeColor($code)
{
    return function ($s) use ($code) {
        global $ISATTY;
        if (!$ISATTY) {
            return $s;
        }

        return "\033[{$code}m{$s}\033[0m";
    };
}

$red = makeColor(31);
$green = makeColor(32);
$yellow = makeColor(33);
$blue = makeColor(34);
$magenta = makeColor(35);
$cyan = makeColor(36);

$bold = makeColor(1);
$underline = makeColor(4);

$grayScale = array_map(function ($el) {
    return makeColor('38;5;' . $el);
}, range(232, 255));


$isDebug = false;

function debugLog($msg)
{
    global $isDebug;
    if (!$isDebug) {
        return;
    }

    echo "DEBUG:httpstat:$msg \n\n";
}


function infoLog($msg)
{
    echo "INFO:httpstat:$msg \n\n";
}


function quit($s, $code = 0)
{
    if ($s) {
        echo($s);
    }
    exit($code);
}


function printHelp()
{
    $help = <<<HELP
Usage: httpstat URL [CURL_OPTIONS]
       httpstat -h | --help
       httpstat --version

Arguments:
  URL     url to request, could be with or without `http(s)://` prefix

Options:
  CURL_OPTIONS  any curl supported options, except for -w -D -o -S -s,
                which are already used internally.
  -h --help     show this screen.
  --version     show version.

Environments:
  HTTPSTAT_SHOW_BODY    Set to `true` to show resposne body in the output,
                        note that body length is limited to 1023 bytes, will be
                        truncated if exceeds. Default is `false`.
  HTTPSTAT_SHOW_SPEED   Set to `true` to show download and upload speed.
                        Default is `false`.
  HTTPSTAT_SAVE_BODY    By default httpstat stores body in a tmp file,
                        set to `false` to disable this feature. Default is `true`
  HTTPSTAT_CURL_BIN     Indicate the curl bin path to use. Default is `curl`
                        from current shell \$PATH.
  HTTPSTAT_DEBUG        Set to `true` to see debugging logs. Default is `false`
HELP;

    echo "$help \n";

}

function main()
{
    global $argv;
    global $curlFormat, $httpsTemplate, $httpTemplate;
    global $yellow, $grayScale, $green, $cyan;

    array_shift($argv);

    if (!$argv) {
        printHelp();
        quit(null, 0);
    }

    // get envs
    global $ENV_SHOW_BODY, $ENV_SHOW_SPEED, $ENV_SAVE_BODY, $ENV_CURL_BIN, $ENV_DEBUG;
    global $isDebug;

    $showBody = strpos(strtolower($ENV_SHOW_BODY->get('false')), 'true') !== false;
    $showSpeed = strpos(strtolower($ENV_SHOW_SPEED->get('false')), 'true') !== false;
    $saveBody = strpos(strtolower($ENV_SAVE_BODY->get('true')), 'true') !== false;
    $curlBin = $ENV_CURL_BIN->get('curl');
    $isDebug = strpos(strtolower($ENV_DEBUG->get('false')), 'true') !== false;


    // log envs
    debugLog(sprintf("ENVs:
    %s: %s
    %s: %s
    %s: %s
    %s: %s
    %s: %s",
        $ENV_SHOW_BODY->key, ($showBody ? 'true' : 'false'),
        $ENV_SHOW_SPEED->key, ($showSpeed ? 'true' : 'false'),
        $ENV_SAVE_BODY->key, ($saveBody ? 'true' : 'false'),
        $ENV_CURL_BIN->key, $curlBin,
        $ENV_DEBUG->key, ($isDebug ? 'true' : 'false')
    ));


    // get url
    $url = $argv[0];
    if (in_array($url, array('-h', '--help'))) {
        printHelp();
        quit(null, 0);
    } elseif ($url == '--version') {
        global $__version__;
        echo "php-httpstat {$__version__} \n";
        quit(null, 0);
    }

    array_shift($argv);

    $curlArgs = $argv;


    // check curl args
    $excludeOptions = array(
        '-w', '--write-out',
        '-D', '--dump-header',
        '-o', '--output',
        '-s', '--silent',
    );
    foreach ($excludeOptions as $k => $v) {
        if (in_array($v, $curlArgs)) {
            quit($yellow("Error: $v is not allowed in extra curl args \n"), 1);
        }
    }

    // tempfile for output
    $bodyF = tmpfile();
    $bodyFName = stream_get_meta_data($bodyF)['uri'];
    fclose($bodyF);

    $headerF = tmpfile();
    $headerFName = stream_get_meta_data($headerF)['uri'];
    fclose($headerF);

    // run cmd
    if (strcasecmp(substr(PHP_OS, 0, 3), 'WIN') != 0) {
        // unix like systems
        $cmdEnv = $_ENV;
        $cmdEnv['LC_ALL'] = 'C';
    }
    else
        // windows
        $cmdEnv = null;

    $cmdArr = array(
        $curlBin,
        '-w', "'{$curlFormat}'",
        '-D', "'{$headerFName}'",
        '-o', "'{$bodyFName}'",
        '-s', '-S'
    );

    $cmdArr = array_merge($cmdArr, $curlArgs);
    $cmdArr = array_merge($cmdArr, array($url));

    $cmd = implode(' ', $cmdArr);

    debugLog("cmd: {$cmd}");

    $p = proc_open($cmd,
        array(
            array("pipe", "r"),
            array("pipe", "w"),
            array("pipe", "w")
        ),
        $pipes,
        sys_get_temp_dir(),
        $cmdEnv);

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $pStatus = proc_get_status($p);
    $exitCode = $pStatus['exitcode'];

    // print stderr
    if ($exitCode == 0 || $exitCode == -1) {
        echo $grayScale[16]($err);
    } else {
        $cmdArr_ = $cmdArr;
        $cmdArr_[2] = '<output-format>';
        $cmdArr_[4] = '<tempfile>';
        $cmdArr_[6] = '<tempfile>';
        echo sprintf("> %s \n", implode(' ', $cmdArr_));
        quit($yellow(sprintf("curl error: %s \n", $err)), $exitCode);
    }


    // parse output
    $d = @json_decode($out, true);
    if ($d === null && json_last_error() !== JSON_ERROR_NONE) {
        echo $yellow(sprintf("Could not decode json: %s \n", json_last_error_msg()));
        echo sprintf("curl result: %d %s %s \n", $exitCode, $grayScale[16]($out), $grayScale[16]($err));
        quit(null, 1);
    }

    foreach ($d as $k => $v) {
        if (strpos($k, 'time_') === 0) {
            $d[$k] = (int)($d[$k] * 1000);
        }
    }

    // calculate ranges
    $d['range_dns'] = $d['time_namelookup'];
    $d['range_connection'] = $d['time_connect'] - $d['time_namelookup'];
    $d['range_ssl'] = $d['time_pretransfer'] - $d['time_connect'];
    $d['range_server'] = $d['time_starttransfer'] - $d['time_pretransfer'];
    $d['range_transfer'] = $d['time_total'] - $d['time_starttransfer'];

    // print header & body summary
    $headers = trim(file_get_contents($headerFName));

    // remove header file
    debugLog("rm header file {$headerFName}");
    unlink($headerFName);

    echo "\n";

    foreach (explode("\n", $headers) as $key => $line) {
        if ($key == 0) {
            $pos = strpos($line, '/');
            echo $green(substr($line, 0, $pos)) . $grayScale[14]('/') . $cyan(substr($line, $pos + 1, strlen($line))) . "\n";
        } else {
            $pos = strpos($line, ':');
            echo $grayScale[14](substr($line, 0, $pos)) . $cyan(substr($line, $pos, strlen($line))) . "\n";
        }
    }

    echo "\n";

    if ($showBody) {
        $bodyLimit = 1024;
        $body = trim(file_get_contents($bodyFName));

        $bodyLen = strlen($body);

        if ($bodyLen > $bodyLimit) {
            echo trim(substr($body, 0, $bodyLimit)) . $cyan('...') . "\n";
            echo "\n";
            $s = sprintf("%s is truncated (%d out of %s)", $green('Body'), $bodyLimit, $bodyLen);
            if ($saveBody) {
                $s .= sprintf(", stored in: %s", $bodyFName);
            }
            $s .= "\n";
            echo $s;
        } else {
            echo "$body \n";
        }
    } else {
        if ($saveBody) {
            echo sprintf("%s stored in: %s\n", $green('Body'), $bodyFName);
        }
    }

    // remove body file
    if (!$saveBody) {
        debugLog(sprintf("rm body file %s", $bodyFName));
        unlink($bodyFName);
    }

    // print stat
    if (strpos($url, 'https://') === 0) {
        $template = $httpsTemplate;
    } else {
        $template = $httpTemplate;
    }

    // colorize template first line
    $tplParts = explode("\n", $template);
    $tplParts[0] = $grayScale[16]($tplParts[0]);
    $template = implode("\n", $tplParts);


    function fmta($s)
    {
        global $cyan;

        $maxLength = 7;
        $s = (string)$s . 'ms';
        if (strlen($s) > $maxLength) {
            $s = substr($s, 0, $maxLength);
        }

        $len = strlen($s);
        $spaces = $maxLength - $len;
        $spaces1 = floor($spaces / 2);
        $spaces2 = $spaces - $spaces1;

        $s_ = str_repeat(' ', $spaces1) . $s . str_repeat(' ', $spaces2);

        return $cyan($s_);
    }

    function fmtb($s)
    {
        global $cyan;

        $maxLength = 7;
        $s = (string)$s . 'ms';
        if (strlen($s) > $maxLength) {
            $s = substr($s, 0, $maxLength);
        }

        $s_ = sprintf('%-7s', $s);
        return $cyan($s_);
    }

    // a
    $stat = str_replace('{a0000}', fmta($d['range_dns']), $template);
    $stat = str_replace('{a0001}', fmta($d['range_connection']), $stat);
    $stat = str_replace('{a0002}', fmta($d['range_ssl']), $stat);
    $stat = str_replace('{a0003}', fmta($d['range_server']), $stat);
    $stat = str_replace('{a0004}', fmta($d['range_transfer']), $stat);
    // b
    $stat = str_replace('{b0000}', fmtb($d['time_namelookup']), $stat);
    $stat = str_replace('{b0001}', fmtb($d['time_connect']), $stat);
    $stat = str_replace('{b0002}', fmtb($d['time_pretransfer']), $stat);
    $stat = str_replace('{b0003}', fmtb($d['time_starttransfer']), $stat);
    $stat = str_replace('{b0004}', fmtb($d['time_total']), $stat);

    echo "\n";
    echo $stat;

    // speed, originally bytes per second
    if ($showSpeed) {
        echo sprintf("speed_download: %.1f KiB/s, speed_upload: %.1f KiB/s \n",
            ($d['speed_download'] / 1024), ($d['speed_upload'] / 1024));
    }
}

main();
