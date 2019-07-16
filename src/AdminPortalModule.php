<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2018, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace SURFnet\VPN\Admin;

use DateInterval;
use DateTime;
use SURFnet\VPN\Common\Http\Exception\HttpException;
use SURFnet\VPN\Common\Http\HtmlResponse;
use SURFnet\VPN\Common\Http\InputValidation;
use SURFnet\VPN\Common\Http\RedirectResponse;
use SURFnet\VPN\Common\Http\Request;
use SURFnet\VPN\Common\Http\Response;
use SURFnet\VPN\Common\Http\Service;
use SURFnet\VPN\Common\Http\ServiceModuleInterface;
use SURFnet\VPN\Common\HttpClient\ServerClient;
use SURFnet\VPN\Common\TplInterface;

class AdminPortalModule implements ServiceModuleInterface
{
    /** @var \SURFnet\VPN\Common\TplInterface */
    private $tpl;

    /** @var \SURFnet\VPN\Common\HttpClient\ServerClient */
    private $serverClient;

    /** @var Graph */
    private $graph;

    /** @var \DateTime */
    private $dateTimeToday;

    public function __construct(TplInterface $tpl, ServerClient $serverClient, Graph $graph)
    {
        $this->tpl = $tpl;
        $this->serverClient = $serverClient;
        $this->graph = $graph;
        $this->dateTimeToday = new DateTime('today');
    }

    /**
     * @return void
     */
    public function init(Service $service)
    {
        $service->get(
            '/',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request) {
                return new RedirectResponse($request->getRootUri().'connections', 302);
            }
        );

        $service->get(
            '/connections',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function () {
                // get the fancy profile name
                $profileList = $this->serverClient->getRequireArray('profile_list');

                $idNameMapping = [];
                foreach ($profileList as $profileId => $profileData) {
                    $idNameMapping[$profileId] = $profileData['displayName'];
                }

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnConnections',
                        [
                            'idNameMapping' => $idNameMapping,
                            'connections' => $this->serverClient->getRequireArray('client_connections'),
                        ]
                    )
                );
            }
        );

        $service->get(
            '/info',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function () {
                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnInfo',
                        [
                            'profileList' => $this->serverClient->getRequireArray('profile_list'),
                        ]
                    )
                );
            }
        );

        $service->get(
            '/users',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function () {
                $userList = $this->serverClient->getRequireArray('user_list');

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnUserList',
                        [
                            'userList' => $userList,
                        ]
                    )
                );
            }
        );

        $service->get(
            '/user',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request) {
                $userId = $request->getQueryParameter('user_id');
                InputValidation::userId($userId);

                $clientCertificateList = $this->serverClient->getRequireArray('client_certificate_list', ['user_id' => $userId]);
                $userMessages = $this->serverClient->getRequireArray('user_messages', ['user_id' => $userId]);

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnUserConfigList',
                        [
                            'userId' => $userId,
                            'userMessages' => $userMessages,
                            'clientCertificateList' => $clientCertificateList,
                            'hasTotpSecret' => $this->serverClient->getRequireBool('has_totp_secret', ['user_id' => $userId]),
                            'isDisabled' => $this->serverClient->getRequireBool('is_disabled_user', ['user_id' => $userId]),
                        ]
                    )
                );
            }
        );

        $service->post(
            '/user',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request) {
                $userId = $request->getPostParameter('user_id');
                InputValidation::userId($userId);
                $userAction = $request->getPostParameter('user_action');
                // no need to explicitly validate userAction, as we will have
                // switch below with whitelisted acceptable values

                switch ($userAction) {
                    case 'disableUser':
                        $this->serverClient->post('disable_user', ['user_id' => $userId]);
                        // kill all active connections for this user
                        $clientConnections = $this->serverClient->getRequireArray('client_connections', ['user_id' => $userId]);
                        foreach ($clientConnections as $profile) {
                            foreach ($profile['connections'] as $connection) {
                                $this->serverClient->post('kill_client', ['common_name' => $connection['common_name']]);
                            }
                        }
                        break;

                    case 'enableUser':
                        $this->serverClient->post('enable_user', ['user_id' => $userId]);
                        break;

                    case 'deleteTotpSecret':
                        $this->serverClient->post('delete_totp_secret', ['user_id' => $userId]);
                        break;

                    default:
                        throw new HttpException('unsupported "user_action"', 400);
                }

                $returnUrl = sprintf('%susers', $request->getRootUri());

                return new RedirectResponse($returnUrl);
            }
        );

        $service->get(
            '/log',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function () {
                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnLog',
                        [
                            'currentDate' => date('Y-m-d H:i:s'),
                            'date_time' => null,
                            'ip_address' => null,
                        ]
                    )
                );
            }
        );

        $service->get(
            '/stats',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function () {
                $stats = $this->serverClient->get('stats');
                if (!\is_array($stats) || !array_key_exists('profiles', $stats)) {
                    // this is an old "stats" format we no longer support,
                    // vpn-server-api-stats has to run again first, which is
                    // done by the crontab running at midnight...
                    $stats = false;
                }
                // get the fancy profile name
                $profileList = $this->serverClient->getRequireArray('profile_list');

                $idNameMapping = [];
                $profiles = [];
                foreach ($profileList as $profileId => $profileData) {
                    if (array_key_exists($profileId, $stats['profiles'])) {
                        $idNameMapping[$profileId] = $profileData['displayName'];
                        $profiles[$profileId] = $stats['profiles'][$profileId];
                    }
                }
                $stats['profiles'] = $profiles;

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnStats',
                        [
                            'stats' => $stats,
                            'generated_at' => false !== $stats ? $stats['generated_at'] : false,
                            'generated_at_tz' => date('T'),
                            'idNameMapping' => $idNameMapping,
                        ]
                    )
                );
            }
        );

        $service->get(
            '/stats/traffic',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request) {
                $profileId = InputValidation::profileId($request->getQueryParameter('profile_id'));
                $response = new Response(
                    200,
                    'image/png'
                );

                $stats = $this->serverClient->getRequireArray('stats');
                $dateByteList = [];
                foreach ($stats['profiles'][$profileId]['days'] as $v) {
                    $dateByteList[$v['date']] = $v['bytes_transferred'];
                }

                $imageData = $this->graph->draw(
                    $dateByteList,
                    /**
                     * @param int $v
                     *
                     * @return string
                     */
                    function ($v) {
                        $suffix = 'B';
                        if ($v > 1024) {
                            $v /= 1024;
                            $suffix = 'kiB';
                        }
                        if ($v > 1024) {
                            $v /= 1024;
                            $suffix = 'MiB';
                        }
                        if ($v > 1024) {
                            $v /= 1024;
                            $suffix = 'GiB';
                        }
                        if ($v > 1024) {
                            $v /= 1024;
                            $suffix = 'TiB';
                        }

                        return sprintf('%d %s ', $v, $suffix);
                    }
                );
                $response->setBody($imageData);

                return $response;
            }
        );

        $service->get(
            '/stats/users',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request) {
                $profileId = InputValidation::profileId($request->getQueryParameter('profile_id'));
                $response = new Response(
                    200,
                    'image/png'
                );

                $stats = $this->serverClient->getRequireArray('stats');
                $dateUsersList = [];
                foreach ($stats['profiles'][$profileId]['days'] as $v) {
                    $dateUsersList[$v['date']] = $v['unique_user_count'];
                }

                $imageData = $this->graph->draw(
                    $dateUsersList
                );
                $response->setBody($imageData);

                return $response;
            }
        );

        $service->get(
            '/messages',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function () {
                $motdMessages = $this->serverClient->getRequireArray('system_messages', ['message_type' => 'motd']);

                // we only want the first one
                if (0 === \count($motdMessages)) {
                    $motdMessage = false;
                } else {
                    $motdMessage = $motdMessages[0];
                }

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnMessages',
                        [
                            'motdMessage' => $motdMessage,
                        ]
                    )
                );
            }
        );

        $service->post(
            '/messages',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request) {
                $messageAction = $request->getPostParameter('message_action');
                switch ($messageAction) {
                    case 'set':
                        // we can only have one "motd", so remove the ones that
                        // already exist
                        $motdMessages = $this->serverClient->getRequireArray('system_messages', ['message_type' => 'motd']);
                        foreach ($motdMessages as $motdMessage) {
                            $this->serverClient->post('delete_system_message', ['message_id' => $motdMessage['id']]);
                        }

                        // no need to validate, we accept everything
                        $messageBody = $request->getPostParameter('message_body');
                        $this->serverClient->post('add_system_message', ['message_type' => 'motd', 'message_body' => $messageBody]);
                        break;
                    case 'delete':
                        $messageId = InputValidation::messageId($request->getPostParameter('message_id'));

                        $this->serverClient->post('delete_system_message', ['message_id' => $messageId]);
                        break;
                    default:
                        throw new HttpException('unsupported "message_action"', 400);
                }

                $returnUrl = sprintf('%smessages', $request->getRootUri());

                return new RedirectResponse($returnUrl);
            }
        );

        $service->post(
            '/log',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request) {
                $dateTime = $request->getPostParameter('date_time');
                InputValidation::dateTime($dateTime);
                $ipAddress = $request->getPostParameter('ip_address');
                InputValidation::ipAddress($ipAddress);

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnLog',
                        [
                            'currentDate' => date('Y-m-d H:i:s'),
                            'date_time' => $dateTime,
                            'ip_address' => $ipAddress,
                            'result' => $this->serverClient->getRequireArrayOrFalse('log', ['date_time' => $dateTime, 'ip_address' => $ipAddress]),
                        ]
                    )
                );
            }
        );
    }

    /**
     * @param \DateInterval $dateInterval
     *
     * @return array<string, int>
     */
    private function createDateList(DateInterval $dateInterval)
    {
        $currentDay = $this->dateTimeToday->format('Y-m-d');
        $dateTime = clone $this->dateTimeToday;
        $dateTime->sub($dateInterval);
        $oneDay = new DateInterval('P1D');

        $dateList = [];
        while ($dateTime < $this->dateTimeToday) {
            $dateList[$dateTime->format('Y-m-d')] = 0;
            $dateTime->add($oneDay);
        }

        return $dateList;
    }
}
