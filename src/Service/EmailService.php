<?php

namespace App\Service;

use CS_REST_Campaigns;
use CS_REST_Subscribers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Header\Headers;

use App\Entity\Member;

class EmailService
{
    protected $params;

    protected $apiKey;

    protected $defaultListId;

    protected $webhookToken;

    protected $client;

    protected $campaignsClient;

    protected $em;

    protected $mailer;

    public function __construct(ParameterBagInterface $params, EntityManagerInterface $em, MailerInterface $mailer)
    {
        $this->params = $params;
        $this->apiKey = $params->get('campaign_monitor.api_key');
        $this->defaultListId = $params->get('campaign_monitor.default_list_id');
        $this->webhookToken = $params->get('campaign_monitor.webhook_token');
        $this->client = new CS_REST_Subscribers(
            $this->defaultListId,
            [
                'api_key' => $this->apiKey
            ]
        );
        $this->campaignsClient = new CS_REST_Campaigns(
            $this->defaultListId,
            [
                'api_key' => $this->apiKey
            ]
        );
        $this->em = $em;
        $this->mailer = $mailer;
    }

    public function isConfigured(): bool
    {
        if ($this->params->get('campaign_monitor.api_key') && $this->params->get('campaign_monitor.default_list_id')) {
            return true;
        }
        return false;
    }

    public function getMemberSubscription(Member $member)
    {
        if (!$member->getPrimaryEmail()) {
            return [];
        }
        $result = $this->client->get($member->getPrimaryEmail(), true);
        return $result->response;
    }

    public function getMemberSubscriptionHistory(Member $member)
    {
        if (!$member->getPrimaryEmail()) {
            return [];
        }
        $result = $this->client->get_history($member->getPrimaryEmail());
        return $result->response;
    }

    public function subscribeMember(Member $member, $resubscribe = false): bool
    {
        if (!$member->getPrimaryEmail()
            || $member->getIsLocalDoNotContact()
            || $member->getStatus()->getIsInactive()
        ) {
            return false;
        }
        $result = $this->client->add([
            'EmailAddress' => $member->getPrimaryEmail(),
            'Name' => $member->getDisplayName(),
            'CustomFields' => $this->buildCustomFieldArray($member),
            'ConsentToTrack' => 'yes',
            'Resubscribe' => $resubscribe
        ]);
        if ($result->was_successful()) {
            return true;
        }
        error_log(json_encode($result->response));
        return false;
    }

    public function updateMember(string $existingEmail, Member $member): bool
    {
        if (!$member->getPrimaryEmail()) {
            return false;
        }
        $result = $this->client->update($existingEmail, [
            'EmailAddress' => $member->getPrimaryEmail(),
            'Name' => $member->getDisplayName(),
            'CustomFields' => $this->buildCustomFieldArray($member),
            'ConsentToTrack' => 'yes'
        ]);
        if ($result->was_successful()) {
            return true;
        }
        error_log(json_encode($result->response));
        return false;
    }

    public function unsubscribeMember(Member $member): bool
    {
        if (!$member->getPrimaryEmail()) {
            return false;
        }
        $result = $this->client->unsubscribe($member->getPrimaryEmail());
        if ($result->was_successful()) {
            return true;
        }
        error_log(json_encode($result->response));
        return false;
    }

    public function deleteMember(Member $member): bool
    {
        if (!$member->getPrimaryEmail()) {
            return false;
        }
        $result = $this->client->delete($member->getPrimaryEmail());
        if ($result->was_successful()) {
            return true;
        }
        error_log(json_encode($result->response));
        return false;
    }

    public function getCampaignById($campaignId): object
    {
        $this->campaignsClient->set_campaign_id($campaignId);
        $result = $this->campaignsClient->get_summary();
        return $result->response;
    }

    public function getWebhookToken(): string
    {
        return $this->webhookToken;
    }

    public function processWebhookBody(string $content): array
    {
        $content = json_decode($content, null, $depth=512, JSON_THROW_ON_ERROR);
        if (!property_exists($content, 'Events') || !is_array($content->Events)) {
            throw new \Exception('Invalid webhook payload. Must have Events.');
        }
        $memberRepository = $this->em->getRepository(Member::class);
        $output = [];
        foreach ($content->Events as $event) {
            switch($event->Type) {
                case 'Update':
                    $member = $memberRepository->findOneBy([
                        'primaryEmail' => $event->OldEmailAddress
                    ]);
                    if (!$member) {
                        $output[] = [
                            'result' => sprintf(
                                'Unable to locate member with %s',
                                $event->OldEmailAddress
                            ),
                            'payload' => $event
                        ];
                        break;
                    }
                    $member->setPrimaryEmail($event->EmailAddress);
                    $this->sendMemberUpdate($member);
                    $this->em->persist($member);
                    $this->em->flush();
                    $output[] = [
                        'result' => sprintf(
                            'Email for %s updated from %s to %s',
                            $member,
                            $event->OldEmailAddress,
                            $event->EmailAddress
                        ),
                        'payload' => $event
                    ];
                    break;
                default:
                    $output[] = [
                        'result' => 'No action taken.',
                        'payload' => $event
                    ];
            }
        }
        return $output;
    }

    public function sendMemberUpdate(Member $member): void
    {
        $headers = new Headers();
        $headers->addTextHeader('X-Cmail-GroupName', 'Member Record Update');
        $headers->addTextHeader('X-MC-Tags', 'Member Record Update');
        $message = new TemplatedEmail($headers);
        $message
            ->to($this->params->get('app.email.to'))
            ->from($this->params->get('app.email.from'))
            ->subject(sprintf('Member Record Update: %s', $member->getDisplayName()))
            ->htmlTemplate('update/email_update.html.twig')
            ->context(['member' => $member])
            ;
        if ($member->getPrimaryEmail()) {
            $message->replyTo($member->getPrimaryEmail());
        }
        $this->mailer->send($message);
    }

    /* Private Methods */

    private function buildCustomFieldArray(Member $member): array
    {
        return [
            [
                'Key' => 'Member Status',
                'Value' => $member->getStatus()->getLabel()
            ],
            [
                'Key' => 'First Name',
                'Value' => $member->getFirstName()
            ],
            [
                'Key' => 'Preferred Name',
                'Value' => $member->getPreferredName()
            ],
            [
                'Key' => 'Middle Name',
                'Value' => $member->getMiddleName()
            ],
            [
                'Key' => 'Last Name',
                'Value' => $member->getLastName()
            ],
            [
                'Key' => 'Class Year',
                'Value' => $member->getClassYear()
            ],
            [
                'Key' => 'Local Identifier',
                'Value' => $member->getLocalIdentifier()
            ],
            [
                'Key' => 'External Identifier',
                'Value' => $member->getExternalIdentifier()
            ],
            [
                'Key' => 'Primary Telephone Number',
                'Value' => $member->getPrimaryTelephoneNumber()
            ],
            [
                'Key' => 'Mailing Address Line 1',
                'Value' => $member->getMailingAddressLine1()
            ],
            [
                'Key' => 'Mailing Address Line 2',
                'Value' => $member->getMailingAddressLine2()
            ],
            [
                'Key' => 'Mailing City',
                'Value' => $member->getMailingCity()
            ],
            [
                'Key' => 'Mailing State',
                'Value' => $member->getMailingState()
            ],
            [
                'Key' => 'Mailing Postal Code',
                'Value' => $member->getMailingPostalCode()
            ],
            [
                'Key' => 'Mailing Country',
                'Value' => $member->getMailingCountry()
            ],
            [
                'Key' => 'Employer',
                'Value' => $member->getEmployer()
            ],
            [
                'Key' => 'Job Title',
                'Value' => $member->getJobTitle()
            ],
            [
                'Key' => 'Occupation',
                'Value' => $member->getOccupation()
            ],
            [
                'Key' => 'LinkedIn Profile',
                'Value' => $member->getLinkedinUrl()
            ],
            [
                'Key' => 'Facebook Profile',
                'Value' => $member->getFacebookUrl()
            ],
            [
                'Key' => 'Tags',
                'Value' => $member->getTagsAsCSV()
            ],
            [
                'Key' => 'Update Token',
                'Value' => $member->getUpdateToken()
            ]
        ];
    }
}
