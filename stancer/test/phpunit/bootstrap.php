<?php

/**
 * Bootstrap for unit tests with mocked Dolibarr environment
 *
 * Strategy: Create a mock Dolibarr structure with stub files BEFORE
 * loading composer autoload to prevent real Dolibarr classes from loading
 */

// 1. Define test constant FIRST
if (!defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}

// 2. Create mock Dolibarr directory structure BEFORE autoload
$mockDolibarrRoot = sys_get_temp_dir() . '/dolibarr-mock-' . getmypid();

// Create directories
$directories = [
    '/core/class',
    '/core/lib',
    '/compta/facture/class',
    '/societe/class',
    '/cron/class',
    '/comm/action/class',
];

foreach ($directories as $dir) {
    $path = $mockDolibarrRoot . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

// Create stub PHP files that define nothing (prevents real files from loading)
$stubFiles = [
    '/core/class/commonobject.class.php',
    '/core/class/commonobjectline.class.php',
    '/core/class/commoninvoice.class.php',
    '/core/lib/files.lib.php',
    '/core/lib/functions.lib.php',
    '/core/lib/functions2.lib.php',
    '/core/lib/geturl.lib.php',
    '/compta/facture/class/facture.class.php',
    '/societe/class/companypaymentmode.class.php',
    '/societe/class/societe.class.php',
    '/cron/class/cronjob.class.php',
    '/comm/action/class/actioncomm.class.php',
];

foreach ($stubFiles as $stubFile) {
    $filePath = $mockDolibarrRoot . $stubFile;
    if (!file_exists($filePath)) {
        file_put_contents($filePath, '<?php // Stub file for unit tests');
    }
}

// 3. Define DOL_DOCUMENT_ROOT to mock directory BEFORE autoload
if (!defined('DOL_DOCUMENT_ROOT')) {
    define('DOL_DOCUMENT_ROOT', $mockDolibarrRoot);
}

if (!defined('MAIN_DB_PREFIX')) {
    define('MAIN_DB_PREFIX', 'llx_');
}

if (!defined('DOL_VERSION')) {
    define('DOL_VERSION', '18.0.0');
}

// 4. NOW load composer autoload (with mock DOL_DOCUMENT_ROOT already set)
require_once __DIR__ . '/../../vendor/autoload.php';

// 4b. Load HTTP mock system for API testing
require_once __DIR__ . '/Mocks/HttpMock.php';

// 5. Define mock classes
class DoliDB
{
    public function query($sql)
    {
        return true;
    }
    public function fetch_object($result)
    {
        return null;
    }
    public function num_rows($result)
    {
        return 0;
    }
    public function escape($value)
    {
        return addslashes($value);
    }
    public function begin()
    {
    }
    public function commit()
    {
    }
    public function rollback()
    {
    }
    public function lasterror()
    {
        return '';
    }
    public function free($result)
    {
    }
    public function jdate($date)
    {
        return is_numeric($date) ? (int) $date : strtotime($date);
    }
}

class HookManager
{
    public $resPrint = '';
    public function initHooks($hooks)
    {
    }
    public function executeHooks($method, $parameters = [], $object = null, $action = '')
    {
        return 0;
    }
}

class CommonObject
{
    public $db;
    public $id;
    public $ref;
    public $element;
    public $table_element;
    public $module;
    public $error;
    public $errors = [];
    public $status;
    public $fields = [];

    public function createCommon($user, $notrigger = false)
    {
        $this->id = 1;
        return 1;
    }
    public function updateCommon($user, $notrigger = false)
    {
        return 1;
    }
    public function deleteCommon($user, $notrigger = false)
    {
        return 1;
    }
    public function fetchCommon($id, $ref = null, $morewhere = '')
    {
        return 1;
    }
    public function fetchLinesCommon()
    {
        return 1;
    }
    public function setStatusCommon($user, $status, $notrigger = 0, $trigger = '')
    {
        return 1;
    }
    public function deleteLineCommon($user, $idline, $notrigger = 0)
    {
        return 1;
    }
    public function initAsSpecimenCommon()
    {
    }
    public function getFieldList($alias = '')
    {
        return '*';
    }
    public function setVarsFromFetchObj($obj)
    {
    }
    public function call_trigger($trigger, $user)
    {
        return 1;
    }
    public function getNextNumRef()
    {
        return 1;
    }
    public function commonGenerateDocument($modelpath, $modele, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
    {
        return 1;
    }
    public function showOutputField($val, $key, $object, $moreparam = '', $keysuffix = '', $keyprefix = '', $showsize = 0)
    {
        return $object;
    }
    public function fetchAll($sortorder = '', $sortfield = '', $limit = 0, $offset = 0, array $filter = array(), $filtermode = 'AND')
    {
        return [];
    }
    public function getTooltipContentArray($params)
    {
        return ['<b>Ref:</b> ' . ($this->ref ?? '')];
    }
}

class CommonObjectLine extends CommonObject
{
    public $fk_element;
    public $position;
}

class CommonInvoice extends CommonObject
{
    const STATUS_DRAFT = 0;
    const STATUS_VALIDATED = 1;
    const STATUS_CLOSED = 2;
}

class Facture extends CommonInvoice
{
    const TYPE_STANDARD = 0;
    const TYPE_CREDIT_NOTE = 2;

    public $socid;
    public $total_ttc;
    public $date;
    public $paye;
    public $fk_statut;
    public $linkedObjectsIds = [];

    public function __construct($db)
    {
        $this->db = $db;
    }
    public function fetch($id, $ref = '')
    {
        return 1;
    }
    public function create($user)
    {
        $this->id = 1;
        return 1;
    }
    public function getSommePaiement()
    {
        return 0;
    }
    public function setPaid($user)
    {
        return 1;
    }
    public function getNomUrl($a = 0, $b = '', $c = 0, $d = 0, $e = '', $f = 0)
    {
        return '';
    }
    public function fetchObjectLinked($a = '', $b = '')
    {
    }
}

class CompanyPaymentMode extends CommonObject
{
    public function __construct($db)
    {
        $this->db = $db;
    }
    public function fetch($id)
    {
        return 1;
    }
}

class CompanyPaymentModeStancer extends CompanyPaymentMode
{
    public $fk_soc;
    public $stancer_account;
    public $label;
}

class Cronjob extends CommonObject
{
    public $datelastresult;
    public $datelastrun;

    public function __construct($db)
    {
        $this->db = $db;
    }
    public function fetch($id, $module = '', $method = '')
    {
        return 1;
    }
}

class ActionComm extends CommonObject
{
    public $type_code;
    public $code;
    public $label;
    public $datep;
    public $datef;
    public $percentage;
    public $socid;
    public $contact_id;
    public $authorid;
    public $userownerid;
    public $note_private;
    public $fk_element;
    public $elementtype;
    public $ref_ext;
    public $userassigned;
    public $fulldayevent;
    public $transparency;
    public $socpeopleassigned;

    public function __construct($db)
    {
        $this->db = $db;
    }
    public function create($user, $notrigger = 0)
    {
        $this->id = 1;
        return 1;
    }
}

class Societe extends CommonObject
{
    public $name;
    public $client;
    public $fournisseur;

    public function __construct($db)
    {
        $this->db = $db;
    }
    public function create($user)
    {
        $this->id = 1;
        return 1;
    }
}

class User
{
    public $id = 1;
    public $login = 'testuser';
    public $rights;

    public function __construct($db = null)
    {
        $this->rights = new stdClass();
    }
}

// 6. Define mock Dolibarr functions
function dol_syslog($message, $level = 0)
{
}

function dol_now()
{
    return time();
}

function dol_print_date($time, $format = '')
{
    return date('Y-m-d H:i:s', $time);
}

function getDolGlobalInt($key, $default = 0)
{
    global $conf;
    return isset($conf->global->$key) ? (int) $conf->global->$key : $default;
}

function getDolGlobalString($key, $default = '')
{
    global $conf;
    return isset($conf->global->$key) ? (string) $conf->global->$key : $default;
}

function setEventMessage($message, $style = 'mesgs')
{
}

function setEventMessages($message, $errors = null, $style = 'mesgs')
{
}

function dol_include_once($path, $classname = '')
{
}

function dol_getIdFromCode($db, $code, $table, $field, $id, $entityin = 0)
{
    return 1;
}

function isModEnabled($module)
{
    return true;
}

function getEntity($element, $shared = 1, $currentobject = null)
{
    global $conf;
    return $conf->entity ?? 1;
}

function dol_strlen($string, $stringencoding = 'UTF-8')
{
    return mb_strlen($string, $stringencoding);
}

function dol_sanitizeFileName($str, $newstr = '_', $unaccent = 1)
{
    return preg_replace('/[^a-zA-Z0-9_-]/', $newstr, $str);
}

function dol_dir_list($path, $types = 'all', $recursive = 0, $filter = '', $excludefilter = null, $sortcriteria = 'name', $sortorder = SORT_ASC, $mode = 0, $nohook = 0, $relativename = '', $donotfollowsymlinks = 0, $nbsecondsold = 0)
{
    return [];
}

function dol_print_error($db = '', $error = '', $errors = null)
{
}

function dol_buildpath($path, $type = 0, $returnemptyifnotfound = 0)
{
    return $path;
}

function img_picto($titlealt, $picto, $moreatt = '', $pictoisfullpath = false, $srconly = 0, $notitle = 0, $alt = '', $morecss = '', $marginleftonlyshort = 2)
{
    return '<img src="' . $picto . '" alt="' . $titlealt . '">';
}

function img_object($titlealt, $picto, $moreatt = '', $pictoisfullpath = false, $srconly = 0, $notitle = 0)
{
    return img_picto($titlealt, $picto, $moreatt, $pictoisfullpath, $srconly, $notitle);
}

function dolGetStatus($statusLabel, $statusLabelShort = '', $html = '', $statusType = 'status0', $displayMode = 0, $url = '', $params = array())
{
    return $statusLabel;
}

function dol_escape_htmltag($stringtoescape, $keepb = 0, $keepn = 0, $noescapetags = '', $escapeonlyhtmltags = 0, $cleanalsoithtmlchars = 0)
{
    return htmlspecialchars($stringtoescape, ENT_QUOTES, 'UTF-8');
}

function dolExplodeIntoArray($string, $sep = ';', $val = '=')
{
    $result = [];
    $parts = explode($sep, $string);
    foreach ($parts as $part) {
        $kv = explode($val, $part);
        if (count($kv) == 2) {
            $result[$kv[0]] = $kv[1];
        }
    }
    return $result;
}

function price($amount, $form = 0, $outlangs = '', $trunc = 1, $rounding = -1, $forcerounding = -1, $currency_code = '')
{
    return number_format((float) $amount, 2, '.', ' ');
}

// Stub for stancer lib function normally loaded via dol_include_once (no-op here).
// Returns 0 so callers fall back to their non-tag resolution path.
function stancerGetCustomerSocidFromTag($tag)
{
    return 0;
}

/**
 * Mock getURLContent for API testing
 *
 * Uses HttpMock class to return predefined responses
 *
 * @param string $url         URL to fetch
 * @param string $postorget   HTTP method (GET, POST, POSTALREADYFORMATED, etc.)
 * @param string $param       Request parameters/body
 * @param int    $followlocation Follow redirects
 * @param array  $addheaders  Additional headers
 * @param array  $allowedschemes Allowed URL schemes
 * @param int    $localurl    Local URL flag
 * @param int    $ssl_verifypeer SSL verification
 * @return array Response array with http_code, content, curl_error_no, curl_error_msg
 */
function getURLContent($url, $postorget = 'GET', $param = '', $followlocation = 1, $addheaders = [], $allowedschemes = ['https'], $localurl = 0, $ssl_verifypeer = -1)
{
    // Convert Dolibarr method names to standard HTTP methods
    $method = $postorget;
    if ($postorget === 'POSTALREADYFORMATED') {
        $method = 'POST';
    } elseif ($postorget === 'PUTALREADYFORMATED') {
        $method = 'PUT';
    }

    // Use HttpMock if active
    if (HttpMock::isActive()) {
        return HttpMock::getResponse($url, $method, $param);
    }

    // Fallback: return empty response (no real HTTP in unit tests)
    return [
        'http_code' => 0,
        'content' => '',
        'curl_error_no' => 7,
        'curl_error_msg' => 'Mock HTTP: HttpMock not active, no real HTTP calls in unit tests',
    ];
}

// 7. Initialize global objects
global $conf;
$conf = new stdClass();
$conf->cache = [];
$conf->global = new stdClass();
$conf->entity = 1;
$conf->stancer = new stdClass();
$conf->stancer->enabled = 1;

global $langs;
$langs = new class {
    public function load($domain)
    {
    }
    public function loadLangs($domains)
    {
    }
    public function trans($key, ...$args)
    {
        return $key;
    }
    public function transnoentitiesnoconv($key, ...$args)
    {
        return $key;
    }
};

global $user;
$user = new User();

global $db;
$db = new DoliDB();

global $hookmanager;
$hookmanager = new HookManager();

global $action;
$action = '';

if (!isset($_SERVER['PHP_SELF'])) {
    $_SERVER['PHP_SELF'] = '/test.php';
}

// 8. Cleanup mock directory on shutdown
register_shutdown_function(function () use ($mockDolibarrRoot) {
    if (is_dir($mockDolibarrRoot)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($mockDolibarrRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($mockDolibarrRoot);
    }
});
