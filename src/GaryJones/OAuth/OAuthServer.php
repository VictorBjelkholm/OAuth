<?php
namespace GaryJones\OAuth;

class OAuthServer
{
    protected $timestamp_threshold = 300; // in seconds, five minutes
    protected $version = '1.0';
    protected $signature_methods = array();
    protected $data_store;

    public function __construct(OAuthDataStore $data_store)
    {
        $this->data_store = $data_store;
    }

    public function addSignatureMethod($signature_method)
    {
        $this->signature_methods[$signature_method->getName()] =
            $signature_method;
    }

    // high level functions

    /**
     * process a request_token request
     * returns the request token on success
     */
    public function fetchRequestToken(&$request)
    {
        $this->getVersion($request);

        $client = $this->getClient($request);

        // no token required for the initial token request
        $token = null;

        $this->checkSignature($request, $client, $token);

        // Rev A change
        $callback = $request->getParameter('oauth_callback');
        $new_token = $this->data_store->newRequestToken($client, $callback);

        return $new_token;
    }

    /**
     * process an access_token request
     * returns the access token on success
     */
    public function fetchAccessToken(&$request)
    {
        $this->getVersion($request);

        $client = $this->getClient($request);

        // requires authorized request token
        $token = $this->getToken($request, $client, 'request');

        $this->checkSignature($request, $client, $token);

        // Rev A change
        $verifier = $request->getParameter('oauth_verifier');
        $new_token = $this->data_store->newAccessToken($token, $client, $verifier);

        return $new_token;
    }

    /**
     * verify an api call, checks all the parameters
     */
    public function verifyRequest(&$request)
    {
        $this->getVersion($request);
        $client = $this->getClient($request);
        $token = $this->getToken($request, $client, 'access');
        $this->checkSignature($request, $client, $token);
        return array($client, $token);
    }

    // Internals from here
    /**
     * version 1
     */
    private function getVersion(&$request)
    {
        $version = $request->getParameter('oauth_version');
        if (!$version) {
            // Service Providers MUST assume the protocol version to be 1.0 if this parameter is not present.
            // Chapter 7.0 ("Accessing Protected Ressources")
            $version = '1.0';
        }
        if ($version !== $this->version) {
            throw new OAuthException("OAuth version '$version' not supported");
        }
        return $version;
    }

    /**
     * figure out the signature with some defaults
     */
    private function getSignatureMethod($request)
    {
        $signature_method = $request instanceof OAuthRequest ? $request->getParameter('oauth_signature_method') : null;

        if (!$signature_method) {
            // According to chapter 7 ("Accessing Protected Ressources") the signature-method
            // parameter is required, and we can't just fallback to PLAINTEXT
            throw new OAuthException('No signature method parameter. This parameter is required');
        }

        if (!in_array($signature_method, array_keys($this->signature_methods))) {
            throw new OAuthException(
                "Signature method '$signature_method' not supported, try one of the following: " .
                implode(", ", array_keys($this->signature_methods))
            );
        }
        return $this->signature_methods[$signature_method];
    }

    /**
     * try to find the client for the provided request's client key
     */
    private function getClient($request)
    {
        $client_key = $request instanceof OAuthRequest ? $request->getParameter('oauth_consumer_key') : null;

        if (!$client_key) {
            throw new OAuthException('Invalid client key');
        }

        $client = $this->data_store->lookupClient($client_key);
        if (!$client) {
            throw new OAuthException('Invalid client');
        }

        return $client;
    }

    /**
     * try to find the token for the provided request's token key
     */
    private function getToken($request, $client, $token_type = 'access')
    {
        $token_field = $request instanceof OAuthRequest ? $request->getParameter('oauth_token') : null;

        $token = $this->data_store->lookupToken($client, $token_type, $token_field);
        if (!$token) {
            throw new OAuthException("Invalid $token_type token: $token_field");
        }
        return $token;
    }

    /**
     * all-in-one function to check the signature on a request
     * should guess the signature method appropriately
     */
    private function checkSignature($request, $client, $token)
    {
        // this should probably be in a different method
        $timestamp = $request instanceof OAuthRequest ? $request->getParameter('oauth_timestamp') : null;
        $nonce = $request instanceof OAuthRequest ? $request->getParameter('oauth_nonce') : null;

        $this->checkTimestamp($timestamp);
        $this->checkNonce($client, $token, $nonce, $timestamp);

        $signature_method = $this->getSignatureMethod($request);

        $signature = $request->getParameter('oauth_signature');
        $valid_sig = $signature_method->checkSignature($request, $client, $token, $signature);

        if (!$valid_sig) {
            throw new OAuthException('Invalid signature');
        }
    }

    /**
     * check that the timestamp is new enough
     */
    private function checkTimestamp($timestamp)
    {
        if (!$timestamp) {
            throw new OAuthException('Missing timestamp parameter. The parameter is required');
        }

        // verify that timestamp is recentish
        $now = time();
        if (abs($now - $timestamp) > $this->timestamp_threshold) {
            throw new OAuthException("Expired timestamp, yours $timestamp, ours $now");
        }
    }

    /**
     * check that the nonce is not repeated
     */
    private function checkNonce($client, $token, $nonce, $timestamp)
    {
        if (!$nonce) {
            throw new OAuthException('Missing nonce parameter. The parameter is required');
        }

        // verify that the nonce is uniqueish
        $found = $this->data_store->lookupNonce($client, $token, $nonce, $timestamp);
        if ($found) {
            throw new OAuthException('Nonce already used: ' . $nonce);
        }
    }
}
