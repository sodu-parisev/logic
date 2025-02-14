<?php

namespace App\Operations\API\QBO;

use App\Exceptions\LogicException;
use App\Models\Integration;
use App\Operations\API\APICore;
use App\Operations\Core\LogicStore;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use QuickBooksOnline\Payments\OAuth\OAuth2Authenticator;
use QuickBooksOnline\Payments\PaymentClient;

class  QBOCore extends APICore
{
    const REFRESH_TOKEN = 'QBO_RTOKEN';
    const ACCESS_TOKEN  = 'QBO_ATOKEN';

    public PaymentClient       $qclient;
    public OAuth2Authenticator $authenticator;
    public string              $mode;
    public string              $scope = "com.intuit.quickbooks.accounting";
    public ?string             $cid;
    public object              $config;
    public LogicStore          $ls;

    /**
     * Build and setup keys
     */
    public function __construct(object $config)
    {
        parent::__construct();
        if (!isset($config->qbo_client_id) || !$config->qbo_client_id) return;
        $this->qclient = new PaymentClient();
        $this->mode = env('APP_ENV') == 'local' ? 'sandbox' : 'production';
        $this->config = $config;
        $mode = $this->mode;
        $this->authenticator = OAuth2Authenticator::create([
            'client_id'     => $config->qbo_client_id,
            'client_secret' => $config->qbo_client_secret,
            'redirect_uri'  => $this->generateRedirect(),       // Where does QBO redirect when authorized?
            'environment'   => $mode
        ]);
        $this->ls = new LogicStore();
        $this->ls->init(self::ACCESS_TOKEN, '', "Quickbooks Online Access Token");
        $this->ls->init(self::REFRESH_TOKEN, '', "Quickbooks Online Refresh Token");

    }

    /**
     * Get redirect url based on environment and keys.
     * THis will be generated by the authenticator
     *
     * @return string
     */
    public function getRedirectUrl(): string
    {
        return $this->authenticator->generateAuthCodeURL($this->scope);
    }

    /**
     * Generate the redirect url using settings and our mode.
     * @return string
     */
    private function generateRedirect(): string
    {
        return $this->mode == 'sandbox'
            ? env('QBO_SANDBOX_CALLBACK')
            : setting('brand.url') . "/oa/qbo/callback";
    }

    /**
     * After app is authorized, this will give us our keys.
     * @param Request $request
     * @return void
     * @throws LogicException
     */
    public function processCallback(Request $request): void
    {
        $this->acceptCode($request->code, $request->realmId);
    }

    /**
     * When we sign in to QBO, it will return a code, this code this is
     * accepted here, and we will exchange it for keys.
     * @param mixed $code
     * @throws LogicException
     */
    public function acceptCode(string $code, string $realmId)
    {
        $req = $this->authenticator->createRequestToExchange($code);
        $res = $this->qclient->send($req);
        if ($res->failed())
        {
            $errorMessage = $res->getBody();
            throw new LogicException($errorMessage);
        }
        else
        {
            //Get the keys
            $data = json_decode($res->getBody());
            $this->ls->store(self::REFRESH_TOKEN, $data->refresh_token);
            $this->ls->store(self::ACCESS_TOKEN, $data->access_token);
            $i = Integration::where('ident', 'qbo')->first();
            $data = $i->unpacked;
            $data->qbo_cid = $realmId;
            $i->update(['data' => $data]);
        }
    }

    /**
     * Form a request to quickbooks
     * @param string $endpoint
     * @param string $method
     * @param array  $params
     * @param bool   $retry
     * @return mixed
     * @throws GuzzleException
     * @throws LogicException
     */
    public function qsend(string $endpoint, string $method = 'get', array $params = [], bool $retry = true): mixed
    {
        $this->setHeaders([
            'Authorization' => 'Bearer ' . $this->ls->get(self::ACCESS_TOKEN),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ]);
        $base = $this->mode == 'sandbox'
            ? "https://sandbox-quickbooks.api.intuit.com/v3/company/{$this->config->qbo_cid}/"
            : "https://quickbooks.api.intuit.com/v3/company/{$this->config->qbo_cid}/";
        try
        {
            return $this->send($base . $endpoint, $method, $params);
        } catch (LogicException) // Possible the access token expired.
        {
            if ($this->responseCode == 401 && $retry)
            {
                info("Error Querying QBO, Attempting to refresh access token.");
                $this->refreshAccessToken();
                return $this->qsend($endpoint, $method, $params, false); // Try once more.
            }
        }
    }

    /**
     * Refresh Access Token
     * @return void
     * @throws LogicException
     */
    private function refreshAccessToken(): void
    {
        $req = $this->authenticator->createRequestToRefresh($this->ls->get(self::REFRESH_TOKEN));
        $res = $this->qclient->send($req);
        $data = json_decode($res->getBody());
        if (!isset($data->refresh_token))
        {
            // Something went wrong clear out everything.
            $link = "<a href='" . setting('brand.url') . "/oa/qbo/authorize'>click to reauthorize</a>";
            throw new LogicException("Unable to get token from Quickbooks via Refresh Request. Reauthorize QBO: " . $link);
        }
        $this->ls->store(self::REFRESH_TOKEN, $data->refresh_token);
        $this->ls->store(Self::ACCESS_TOKEN, $data->access_token ?? '');
    }

    /**
     * Query for a single or multiple records.
     * @param string     $object
     * @param string     $property
     * @param string|int $matches
     * @param bool       $single
     * @return mixed
     * @throws GuzzleException|LogicException
     */
    public function query(string $object, string $property, string|int $matches, bool $single = true): mixed
    {
        $q = sprintf("SELECT * from %s WHERE %s = '%s'", $object, $property, $matches);
        $res = $this->qsend("query", 'get', ['query' => $q]);
        if (isset($res->QueryResponse->{$object}))
        {
            return $single ? $res->QueryResponse->{$object}[0] : $res->QueryResponse->$object;
        }
        return null;
    }


}
