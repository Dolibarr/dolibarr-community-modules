# Add a PDP / Access Point provider from an external module

The module ships with the providers it knows about (Esalink, SuperPDP...). Any other module can add
its own without patching this one: it declares it through a hook, and ships a class extending
`AbstractPDPProvider`.

Everything else comes for free: the setup page of the module builds the configuration screen of the
provider from the provider itself, the tokens are stored and refreshed by the base class, the API
calls are logged in the "API calls" list, and the invoices, the status messages and the cron
synchronization go through the provider exactly like for a native one.

Reference implementation to copy: `einvoicing/class/providers/TestPDPProvider.class.php`. It is the
`TESTPDP` entry of the provider list, offered in the setup page when the developer tools of the
module are enabled (`EINVOICING_ALLOW_DEVTOOLS`). It calls nothing over the network, so it can be
selected and clicked through to check a new integration is correctly wired before writing any HTTP.

## 1. Declare the provider (hook)

In the descriptor of your module (`core/modules/modMyModule.class.php`):

```php
$this->module_parts = array(
    'hooks' => array('einvoicingproviders'),
);
```

In `class/actions_mymodule.class.php`:

```php
/**
 * Declare the PDP providers brought by this module.
 *
 * @param  array         $parameters   Parameters, holds 'providersList' (the providers already declared)
 * @param  object|null   $object       Not used here
 * @param  string        $action       Current action
 * @param  HookManager   $hookmanager  Hook manager
 * @return int                         0 to let the other hooks run
 */
public function addPDPProviders($parameters, &$object, &$action, $hookmanager)
{
    global $langs;

    $this->results = array(
        'MYPDP' => array(
            'class'                  => 'MyPDPProvider',              // mandatory, extends AbstractPDPProvider
            'classpath'              => '/mymodule/class/providers/',  // mandatory, relative to the Dolibarr root
            'position'               => 50,
            'provider_countries'     => array('FR'),
            'provider_name'          => 'My PDP',                      // label shown in the provider selector
            'description'            => 'My PDP Integration',
            'is_enabled'             => 1,
            'prod_account_admin_url' => 'https://mypdp.example.com/signup',
            'test_account_admin_url' => 'https://sandbox.mypdp.example.com/signup',
        ),
    );

    return 0;
}
```

Only `class` and `classpath` are mandatory; the other keys fall back to a working default
(`position` 500, `provider_countries` `array('all')`, `provider_name` the code of the provider,
`is_enabled` 1, no account url).

Rules:

- the key of the entry (`MYPDP` here) is the code stored in `EINVOICING_PDP`. Pick something
  unlikely to collide, it is global to the Dolibarr installation;
- an entry whose key is already taken by a provider of the module is **ignored** (a warning is
  written in the log). A module never redefines a native provider;
- the provider only shows up while your module is enabled. If the user disables it while it was the
  selected provider, the setup page says so and offers the other providers;
- the hook runs on every instantiation of `PDPProviderManager`, including in the cron job. Keep it to
  building the array: no query, no API call.

## 2. Write the provider class

`/mymodule/class/providers/MyPDPProvider.class.php`:

```php
dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/class/protocols/ProtocolManager.class.php');
dol_include_once('einvoicing/class/call.class.php');   // needed by logCall()

class MyPDPProvider extends AbstractPDPProvider
{
    public $name = 'MyPDP';

    public function __construct($db)
    {
        parent::__construct($db);

        $this->config = array(
            'provider_url'   => 'https://mypdp.example.com',
            'prod_auth_url'  => 'https://api.mypdp.example.com/v1/',
            'prod_api_url'   => 'https://api.mypdp.example.com/v1/',
            'test_auth_url'  => 'https://sandbox.mypdp.example.com/v1/',
            'test_api_url'   => 'https://sandbox.mypdp.example.com/v1/',
            'dol_prefix'     => 'EINVOICING_MYPDP',
            'has_validator'  => 0,
            'live'           => getDolGlobalInt('EINVOICING_LIVE', 0),
        );

        $this->tokenData = $this->fetchOAuthTokenDB();

        $ProtocolManager = new ProtocolManager($this->db);
        $this->exchangeProtocol = $ProtocolManager->getProtocol(getDolGlobalString('EINVOICING_PROTOCOL'));
    }

    // ... the methods below
}
```

The class **must** extend `AbstractPDPProvider`: a class that does not is refused by
`PDPProviderManager::getProvider()`, with a line in the log, rather than failing later on a missing
method.

### `dol_prefix`, the only naming convention to respect

`$this->config['dol_prefix']` is the prefix of everything belonging to your provider:

- its constants: `EINVOICING_MYPDP_API_KEY`, `EINVOICING_MYPDP_USERNAME`, `EINVOICING_MYPDP_ROUTING_ID`...
- the actions of the setup page: `setEINVOICING_MYPDP_TOKEN`, `callEINVOICING_MYPDP_HEALTHCHECK`,
  `deleteEINVOICING_MYPDP_TOKEN`, `makeEINVOICING_MYPDP_sampleinvoice`;
- the OAuth token rows stored by `saveOAuthTokenDB()` / read by `fetchOAuthTokenDB()`.

Suffix the credentials with `_PROD` for the production ones (`EINVOICING_MYPDP_API_KEY_PROD`), as the
native providers do: `EINVOICING_LIVE` then switches the whole set at once, and the sandbox keys are
never sent to the production platform.

### Methods to implement

Abstract, so the class does not load without them:

| Method | Returns | Called by |
| --- | --- | --- |
| `validateConfiguration($mode = 1)` | `bool` — `$mode` 0: credentials are set, 1: a token is set | before the API calls |
| `getAccessToken()` | `string|null` — new token, saved with `saveOAuthTokenDB()` | setup page, and on an expired token |
| `refreshAccessToken()` | `string|null` | when `isTokenExpired()` is true |
| `checkHealth()` | `array{status_code:int,message:string}` | "Test connection" of the setup page |
| `callApi($resource, $method, $options, $extraHeaders, $callType)` | `array{status_code:int,response:mixed,call_id:?string}` | every other method of your class |
| `sendInvoice($object)` | flow id given by the platform, `false` on failure | validation/transmission of a customer invoice |
| `sendStatusMessage($object, $statusCode, $reasonCode, $paymentData)` | `array{res:int,message:string}` | lifecycle of the e-invoice (received, rejected, cashed...) |
| `sendSampleInvoice($onlymake = 0)` | array of messages, `0` on failure | setup page |
| `validateEInvoiceFile($idinvoice, $filePath)` | `array{res:int,message:string}` | only when `has_validator` is 1 |
| `syncFlows($syncFromDate = 0, $limit = 0)` | `array{res:int,messages:string[],...}` | cron job and "Synchronize" button |
| `syncFlow($flowId, $call_id = null)` | `array{res:int,message:string,action:?string}` | one flow of the synchronization |

Not abstract but required in practice:

- `initFormSetup(&$formSetup, $prefix, $prefixenv, $providersConfig, $TFieldProtocols, $TFieldProfiles)`
  builds the configuration block of your provider in the setup page (credentials, token, actions).
  Without it the page shows nothing to configure. `$prefix` is your `dol_prefix` followed by `_`, and
  `$prefixenv` is `prod` or `test`;
- `deleteAccessToken()` if you display the "remove the connection" link.

### What the base class already does for you

- `getApiUrl($mode)` returns the production or sandbox URL of your config depending on
  `EINVOICING_LIVE` — `$mode` being `auth`, `api`, `ap_api` or `afnor_directory`;
- `saveOAuthTokenDB()` / `fetchOAuthTokenDB()` / `deleteOAuthTokenDB()` / `isTokenExpired()` store and
  read the tokens of your provider;
- `logCall()` records an API call in `llx_einvoicing_call`, on an independent database connection so
  the trace survives a rollback, and redacts the secrets. Call it from your `callApi()` and the whole
  module gets a complete call log;
- `checkRecipientDirectory($idprof1)` pre-checks that a recipient is reachable, through the AFNOR
  directory service (XP Z12-013) when your config declares `prod_afnor_directory_url` /
  `test_afnor_directory_url`;
- `fetchFlowData()`, `fetchFlowXml()`, `resolveFlowProfile()`, `addEvent()`, `generateUuidV4()`,
  `getLastSyncDate()`.

The XML itself is **not** the business of the provider: it is built by the exchange protocol chosen by
the user (CII, Factur-X, UBL), loaded in the constructor above. A provider transports a file, it does
not produce it.

## 3. Check the integration

1. enable your module, then go to `Setup > Modules > E-invoicing`: your provider is in the list;
2. select it: the block built by your `initFormSetup()` appears;
3. enter the credentials, generate the token, run the health check;
4. enable the developer tools of the module (`EINVOICING_ALLOW_DEVTOOLS`) to get the "generate a
   sample invoice" action, which exercises the generation without sending anything;
5. then a real invoice: validate it, transmit it, and follow the "API calls" list of the module,
   which shows every call your `callApi()` logged.
