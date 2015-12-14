<?php

/**
 * Copyright 2015 François Kooman <fkooman@tuxed.net>.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
require_once dirname(__DIR__).'/vendor/autoload.php';

use fkooman\Ini\IniReader;
use fkooman\Rest\Plugin\Authentication\AuthenticationPlugin;
use fkooman\Rest\Plugin\Authentication\Basic\BasicAuthentication;
use fkooman\Rest\Plugin\Authentication\Mellon\MellonAuthentication;
use fkooman\Tpl\Twig\TwigTemplateManager;
use fkooman\Http\Request;
use fkooman\Rest\Service;
use fkooman\Http\RedirectResponse;
use GuzzleHttp\Client;
use fkooman\VPN\AdminPortal\VpnUserPortalClient;
use fkooman\VPN\AdminPortal\VpnServerApiClient;

try {
    $iniReader = IniReader::fromFile(
        dirname(__DIR__).'/config/manage.ini'
    );

    // Authentication
    $authMethod = $iniReader->v('authMethod', false, 'BasicAuthentication');
    switch ($authMethod) {
        case 'MellonAuthentication':
            $auth = new MellonAuthentication(
                $iniReader->v('MellonAuthentication', 'attribute')
            );
            break;
        case 'BasicAuthentication':
            $auth = new BasicAuthentication(
                function ($userId) use ($iniReader) {
                    $userList = $iniReader->v('BasicAuthentication');
                    if (!array_key_exists($userId, $userList)) {
                        return false;
                    }

                    return $userList[$userId];
                },
                array('realm' => 'VPN Admin Portal')
            );
            break;
        default:
            throw new RuntimeException('unsupported authentication mechanism');
    }

    $request = new Request($_SERVER);

    $templateManager = new TwigTemplateManager(
        array(
            dirname(__DIR__).'/views',
            dirname(__DIR__).'/config/views',
        ),
        $iniReader->v('templateCache', false, null)
    );
    $templateManager->setDefault(
        array(
            'rootFolder' => $request->getUrl()->getRoot(),
        )
    );

    // VPN User Portal Configuration
    $serviceUri = $iniReader->v('VpnUserPortal', 'serviceUri');
    $serviceAuth = $iniReader->v('VpnUserPortal', 'serviceUser');
    $servicePass = $iniReader->v('VpnUserPortal', 'servicePass');
    $client = new Client(
        array(
            'defaults' => array(
                'auth' => array($serviceAuth, $servicePass),
            ),
        )
    );
    $vpnUserPortalClient = new VpnUserPortalClient($client, $serviceUri);

    // VPN Server API Configuration
    $serviceUri = $iniReader->v('VpnServerApi', 'serviceUri');
    $serviceAuth = $iniReader->v('VpnServerApi', 'serviceUser');
    $servicePass = $iniReader->v('VpnServerApi', 'servicePass');
    $client = new Client(
        array(
            'defaults' => array(
                'auth' => array($serviceAuth, $servicePass),
            ),
        )
    );
    $vpnServerApiClient = new VpnServerApiClient($client, $serviceUri);

    $service = new Service();
    $service->get(
        '/',
        function (Request $request) use ($templateManager, $vpnServerApiClient, $vpnUserPortalClient) {
            return $templateManager->render(
                'vpnManage',
                array(
                    'connectedClients' => $vpnServerApiClient->getStatus(),
                    'allConfig' => $vpnUserPortalClient->getAllConfigurations(),
                    'users' => $vpnUserPortalClient->getUsers(),
                )
            );
        }
    );

    $service->post(
        '/disconnect',
        function (Request $request) use ($vpnServerApiClient) {
            $socketId = $request->getPostParameter('socket_id');
            $commonName = $request->getPostParameter('common_name');

            // disconnect the client from the VPN service
            $vpnServerApiClient->postDisconnect($socketId, $commonName);

            return new RedirectResponse($request->getUrl()->getRootUrl(), 302);
        }
    );

    $service->post(
        '/blockUser',
        function (Request $request) use ($vpnUserPortalClient) {
            $userId = $request->getPostParameter('user_id');
            $vpnUserPortalClient->blockUser($userId);

            return new RedirectResponse($request->getUrl()->getRootUrl(), 302);
        }
    );

    $service->post(
        '/unblockUser',
        function (Request $request) use ($vpnUserPortalClient) {
            $userId = $request->getPostParameter('user_id');
            $vpnUserPortalClient->unblockUser($userId);

            return new RedirectResponse($request->getUrl()->getRootUrl(), 302);
        }
    );

    $service->post(
        '/revoke',
        function (Request $request) use ($vpnServerApiClient, $vpnUserPortalClient) {
            $socketId = $request->getPostParameter('socket_id');
            $commonName = $request->getPostParameter('common_name');

            // XXX: validate the input
            list($userId, $configName) = explode('_', $commonName, 2);

            // revoke the configuration 
            $vpnUserPortalClient->revokeConfiguration($userId, $configName);

            // trigger CRL reload
            $vpnServerApiClient->postRefreshCrl();

            if (null !== $socketId) {
                // disconnect the client from the VPN service if we know the
                // socketId
                $vpnServerApiClient->postDisconnect($socketId, $commonName);
            }

            return new RedirectResponse($request->getUrl()->getRootUrl(), 302);
        }
    );

    $authenticationPlugin = new AuthenticationPlugin();
    $authenticationPlugin->register($auth, 'user');
    $service->getPluginRegistry()->registerDefaultPlugin($authenticationPlugin);
    $response = $service->run($request);

    # CSP: https://developer.mozilla.org/en-US/docs/Security/CSP
    $response->setHeader('Content-Security-Policy', "default-src 'self'");
    # X-Frame-Options: https://developer.mozilla.org/en-US/docs/HTTP/X-Frame-Options
    $response->setHeader('X-Frame-Options', 'DENY');

    $response->send();
} catch (Exception $e) {
    error_log($e->getMessage());
    die(
        sprintf(
            'ERROR: %s<br>%s',
            $e->getMessage(),
            $e->getTraceAsString()
        )
    );
}
