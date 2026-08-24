<?php
/**
 * Dolibarr Mock Classes for Unit Testing
 *
 * These classes provide minimal mock implementations of Dolibarr core classes
 * to allow unit testing of module classes without a real Dolibarr installation.
 */

/**
 * Mock DoliDB class - simulates database operations
 */
class DoliDB
{
    public $type = 'mysqli';
    public $prefix = 'llx_';
    public $lastquery = '';
    public $lastresult = null;
    public $lasterror = '';
    public $lasterrno = 0;

    private $mockResults = [];
    private $mockRowCount = 0;
    private $mockFetchIndex = 0;

    public function query($sql, $usesavepoint = 0, $type = 'auto')
    {
        $this->lastquery = $sql;
        return true;
    }

    public function escape($value)
    {
        return addslashes($value);
    }

    public function sanitize($value)
    {
        return $value;
    }

    public function num_rows($result)
    {
        return $this->mockRowCount;
    }

    public function fetch_object($result)
    {
        if ($this->mockFetchIndex < count($this->mockResults)) {
            return $this->mockResults[$this->mockFetchIndex++];
        }
        return null;
    }

    public function fetch_array($result)
    {
        $obj = $this->fetch_object($result);
        return $obj ? (array)$obj : null;
    }

    public function free($result = null)
    {
        $this->mockFetchIndex = 0;
        return true;
    }

    public function begin()
    {
        return true;
    }

    public function commit()
    {
        return true;
    }

    public function rollback()
    {
        return true;
    }

    public function lasterror()
    {
        return $this->lasterror;
    }

    public function lasterrno()
    {
        return $this->lasterrno;
    }

    public function order($sortfield, $sortorder = 'ASC')
    {
        if (empty($sortfield)) {
            return '';
        }
        return ' ORDER BY ' . $sortfield . ' ' . $sortorder;
    }

    public function plimit($limit, $offset = 0)
    {
        if ($limit <= 0) {
            return '';
        }
        return ' LIMIT ' . $offset . ', ' . $limit;
    }

    public function idate($timestamp)
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    public function jdate($datetime)
    {
        return strtotime($datetime);
    }

    public function last_insert_id($table, $fieldid = 'rowid')
    {
        return 1;
    }

    public function affected_rows($result)
    {
        return 1;
    }

    public function getFieldList($table)
    {
        return '*';
    }

    /**
     * Set mock results for testing
     */
    public function setMockResults(array $results)
    {
        $this->mockResults = $results;
        $this->mockRowCount = count($results);
        $this->mockFetchIndex = 0;
    }
}

// Alias for backward compatibility
class MockDoliDB extends DoliDB {}

/**
 * Mock CommonObject class - base class for all Dolibarr objects
 */
if (!class_exists('CommonObject')) {
    class CommonObject
    {
        public $id;
        public $rowid;
        public $ref;
        public $entity;
        public $status;
        public $error;
        public $errors = [];

        protected $db;
        public $table_element;
        public $element;
        public $fields = [];

        public function __construct($db = null)
        {
            $this->db = $db;
        }

        public function createCommon($user, $notrigger = 0)
        {
            $this->id = 1;
            $this->rowid = 1;
            return $this->id;
        }

        public function fetchCommon($id, $ref = null, $morewhere = '')
        {
            if ($id > 0 || !empty($ref)) {
                $this->id = $id ?: 1;
                $this->rowid = $this->id;
                return 1;
            }
            return 0;
        }

        public function updateCommon($user, $notrigger = 0)
        {
            return 1;
        }

        public function deleteCommon($user, $notrigger = 0, $forcechilddeletion = 0)
        {
            return 1;
        }

        public function setStatusCommon($user, $status, $notrigger = 0, $triggercode = '')
        {
            $this->status = $status;
            return 1;
        }

        public function getFieldList($alias = 't')
        {
            $list = [];
            foreach (array_keys($this->fields) as $field) {
                $list[] = $alias . '.' . $field;
            }
            return implode(', ', $list);
        }

        public function setVarsFromFetchObj($obj)
        {
            if ($obj) {
                foreach ($obj as $key => $value) {
                    $this->$key = $value;
                }
                if (isset($obj->rowid)) {
                    $this->id = $obj->rowid;
                }
            }
        }

        public function call_trigger($triggercode, $user)
        {
            return 1;
        }

        public function fetchLinesCommon()
        {
            return 0;
        }

        public function deleteLineCommon($user, $idline, $notrigger = 0)
        {
            return 1;
        }

        public function initAsSpecimenCommon()
        {
            $this->id = 0;
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
            return (string)$object;
        }

        public function getLibStatut($mode = 0)
        {
            return $this->status ?? 0;
        }
    }
}

/**
 * Mock CommonObjectLine class
 */
if (!class_exists('CommonObjectLine')) {
    class CommonObjectLine extends CommonObject
    {
        public $fk_parent;
        public $position;
    }
}

/**
 * Mock User class
 */
if (!class_exists('User')) {
    class User extends CommonObject
    {
        public $id = 1;
        public $login = 'testuser';
        public $lastname = 'Test';
        public $firstname = 'User';
        public $email = 'test@example.com';
        public $admin = 0;
        public $rights;

        public function __construct($db = null)
        {
            parent::__construct($db);
            $this->rights = new stdClass();
        }
    }
}

/**
 * Mock Societe class (Third Party)
 */
if (!class_exists('Societe')) {
    class Societe extends CommonObject
    {
        public $table_element = 'societe';
        public $element = 'societe';
        public $name;
        public $nom;
        public $client;
        public $fournisseur;
        public $status = 1;
    }
}

/**
 * Mock Facture class (Invoice)
 */
if (!class_exists('Facture')) {
    class Facture extends CommonObject
    {
        public $table_element = 'facture';
        public $element = 'facture';
        public $ref;
        public $total_ttc;
        public $paye = 0;

        public function getNomUrl($withpicto = 0, $option = '', $max = 0, $short = 0, $moretitle = '')
        {
            return '<a href="#">' . $this->ref . '</a>';
        }
    }
}

/**
 * Mock Commande class (Order)
 */
if (!class_exists('Commande')) {
    class Commande extends CommonObject
    {
        public $table_element = 'commande';
        public $element = 'commande';
        public $ref;
        public $total_ttc;

        public function getNomUrl($withpicto = 0, $option = '', $max = 0, $short = 0, $moretitle = '')
        {
            return '<a href="#">' . $this->ref . '</a>';
        }
    }
}

/**
 * Mock Don class (Donation)
 */
if (!class_exists('Don')) {
    class Don extends CommonObject
    {
        public $table_element = 'don';
        public $element = 'don';
        public $ref;
        public $amount;

        public function getNomUrl($withpicto = 0, $option = '', $max = 0, $short = 0, $moretitle = '')
        {
            return '<a href="#">' . $this->ref . '</a>';
        }
    }
}

/**
 * Mock CompanyPaymentMode class
 */
if (!class_exists('CompanyPaymentMode')) {
    class CompanyPaymentMode extends CommonObject
    {
        public $table_element = 'societe_rib';
        public $element = 'societe_rib';
        public $fk_soc;
        public $type;
        public $label;
        public $bank;
        public $iban_prefix;
        public $bic;
    }
}
