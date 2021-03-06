<?php

namespace App\Controller;

use App\Entity\CommunicationLog;
use App\Entity\Donation;
use App\Entity\Event;
use App\Entity\Member;
use App\Form\MemberCommunicationLogType;
use App\Form\MemberEmailType;
use App\Form\MemberSMSType;
use App\Form\MemberType;
use App\Service\ChartService;
use App\Service\CommunicationLogService;
use App\Service\EmailService;
use App\Service\SmsService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gedmo\Loggable\Entity\LogEntry;
use Sabre\VObject\Component\VCard;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @IsGranted("ROLE_USER")
 * @Route("/directory/member")
 */
class MemberController extends AbstractController
{
    /**
     * @Route("/new", name="member_new")
     * @IsGranted("ROLE_DIRECTORY_MANAGER")
     */
    public function memberNew(Request $request, EntityManagerInterface $entityManager): Response
    {
        $member = new Member();
        $form = $this->createForm(MemberType::class, $member);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($member);
            $entityManager->flush();
            $this->addFlash('success', sprintf('%s created!', $member));

            return $this->redirect($this->generateUrl('member_show', [
                'localIdentifier' => $member->getLocalIdentifier(),
            ]));
        }

        return $this->render('member/new.html.twig', [
            'member' => $member,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{localIdentifier}", name="member_show", options={"expose": true}))
     */
    public function show(Member $member): Response
    {
        return $this->render('member/show.html.twig', [
            'member' => $member,
        ]);
    }

    /**
     * @Route("/{localIdentifier}/edit", name="member_edit")
     * @IsGranted("ROLE_DIRECTORY_MANAGER")
     */
    public function memberEdit(Member $member, Request $request, EntityManagerInterface $entityManager): Response
    {
        $originalMember = clone $member;
        $form = $this->createForm(MemberType::class, $member);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($member);
            $entityManager->flush();
            $this->addFlash('success', sprintf('%s updated!', $member));

            return $this->redirect($this->generateUrl('member_show', [
                'localIdentifier' => $member->getLocalIdentifier(),
            ]));
        }

        return $this->render('member/edit.html.twig', [
            'member' => $originalMember,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{localIdentifier}/delete", name="member_delete")
     * @IsGranted("ROLE_DIRECTORY_MANAGER")
     */
    public function memberDelete(Member $member, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createFormBuilder($member)->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->remove($member);
            $entityManager->flush();
            $this->addFlash('success', sprintf('%s deleted!', $member));

            return $this->redirect($this->generateUrl('home'));
        }

        return $this->render('member/delete.html.twig', [
            'member' => $member,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{localIdentifier}/change-log", name="member_change_log")
     * @IsGranted("ROLE_DIRECTORY_MANAGER")
     */
    public function changeLog(Member $member, EntityManagerInterface $entityManager): Response
    {
        $logEntries = $entityManager->getRepository(LogEntry::class)->getLogEntries($member);

        return $this->render('directory/change_log.html.twig', [
            'member' => $member,
            'logEntries' => $logEntries,
        ]);
    }

    /**
     * @Route("/{localIdentifier}/communications", name="member_communication_log")
     * @IsGranted("ROLE_COMMUNICATIONS_MANAGER")
     */
    public function communicationLog(Member $member, Request $request, EntityManagerInterface $entityManager): Response
    {
        $communicationLogs = $entityManager->getRepository(CommunicationLog::class)->getCommunicationLogsByMember($member);
        $communicationLog = new CommunicationLog();
        $form = $this->createForm(MemberCommunicationLogType::class, $communicationLog, ['timezone' => $this->getUser()->getTimezone()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $communicationLog->setMember($member);
            $communicationLog->setUser($this->getUser());
            $entityManager->persist($communicationLog);
            $entityManager->flush();
            $this->addFlash('success', sprintf('%s communication logged!', $member));

            return $this->redirectToRoute('member_communication_log', ['localIdentifier' => $member->getLocalIdentifier()]);
        }

        return $this->render('member/communications.html.twig', [
            'member' => $member,
            'communicationLogs' => $communicationLogs,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{localIdentifier}/donations", name="member_donations")
     * @IsGranted("ROLE_DONATION_MANAGER")
     */
    public function donations(Member $member, ChartService $chartService, EntityManagerInterface $entityManager): Response
    {
        $donations = $entityManager->getRepository(Donation::class)->findByMember($member);
        $donationsByMonth = $entityManager->getRepository(Donation::class)->getTotalDonationsByMonthForMember($member);
        $totals = $entityManager->getRepository(Donation::class)->getTotalDonationsForMember($member);

        return $this->render('member/donations.html.twig', [
            'member' => $member,
            'donations' => $donations,
            'totals' => $totals,
            'chart_data' => $chartService->buildDonationColumnChartData($donationsByMonth),
        ]);
    }

    /**
     * @Route("/{localIdentifier}/events", name="member_events")
     * @IsGranted("ROLE_EVENT_MANAGER")
     */
    public function events(Member $member, Request $request, EntityManagerInterface $entityManager): Response
    {
        $events = $member->getEvents();
        $form = $this->createFormBuilder()
            ->add('event', EntityType::class, [
                'placeholder' => 'Select Event ...',
                'class' => Event::class,
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('e')
                        ->orderBy('e.startAt', 'DESC');
                },
                'choice_attr' => function (Event $event) use ($member) {
                    if ($event->getAttendees()->contains($member)) {
                        return ['disabled' => true];
                    }

                    return [];
                },
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(),
                ],
            ])
            ->getForm()
        ;
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $event = $form->get('event')->getData();
            $event->addAttendee($member);
            $entityManager->persist($event);
            $entityManager->flush();
            $this->addFlash('success', sprintf('%s updated!', $member));

            return $this->redirect($this->generateUrl('member_events', [
                'localIdentifier' => $member->getLocalIdentifier(),
            ]));
        }

        return $this->render('member/events.html.twig', [
            'member' => $member,
            'events' => $events,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{localIdentifier}/email-subscription", name="member_email_subscription")
     * @IsGranted("ROLE_EMAIL_MANAGER")
     */
    public function emailSubscription(Member $member, EmailService $emailService, EntityManagerInterface $entityManager): Response
    {
        if (!$emailService->isConfigured()) {
            $this->addFlash('danger', 'Email service not configured.');

            return $this->redirectToRoute('member_show', ['localIdentifier' => $member->getLocalIdentifier()]);
        }
        if (!$member->getPrimaryEmail()) {
            $this->addFlash('danger', 'No primary email set for Member.');

            return $this->redirectToRoute('member_show', ['localIdentifier' => $member->getLocalIdentifier()]);
        }
        $subscriber = $emailService->getMemberSubscription($member);
        $subscriberHistory = $emailService->getMemberSubscriptionHistory($member);

        return $this->render('directory/email_subscription.html.twig', [
            'member' => $member,
            'subscriber' => $subscriber,
            'subscriberHistory' => $subscriberHistory,
        ]);
    }

    /**
     * @Route("/{localIdentifier}/add-subscriber", name="member_email_subscribe")
     * @IsGranted("ROLE_EMAIL_MANAGER")
     */
    public function addSubscriber(Member $member, EmailService $emailService): Response
    {
        if (!$emailService->isConfigured()) {
            $this->addFlash('danger', 'Email service not configured.');

            return $this->redirectToRoute('member_show', ['localIdentifier' => $member->getLocalIdentifier()]);
        }
        if ($emailService->subscribeMember($member, true)) {
            $this->addFlash('success', 'Subscriber record created!');
        } else {
            $this->addFlash('danger', 'Unable to subscribe user. Is the email address valid?');
        }

        return $this->redirectToRoute(
            'member_email_subscription',
            [
                'localIdentifier' => $member->getLocalIdentifier(),
            ]
        );
    }

    /**
     * @Route("/{localIdentifier}/update-subscriber", name="member_email_update")
     * @IsGranted("ROLE_EMAIL_MANAGER")
     */
    public function updateSubscriber(Member $member, EmailService $emailService): Response
    {
        if (!$emailService->isConfigured()) {
            $this->addFlash('danger', 'Email service not configured.');

            return $this->redirectToRoute('member_show', ['localIdentifier' => $member->getLocalIdentifier()]);
        }
        if (!$member->getPrimaryEmail()) {
            $this->addFlash('danger', 'Email not set for Member.');

            return $this->redirectToRoute('member_show', ['localIdentifier' => $member->getLocalIdentifier()]);
        }
        if ($emailService->updateMember($member->getPrimaryEmail(), $member)) {
            $this->addFlash('success', 'Subscriber record updated!');
        } else {
            $this->addFlash('danger', 'Unable to update Subscriber record.');
        }

        return $this->redirectToRoute(
            'member_email_subscription',
            [
                'localIdentifier' => $member->getLocalIdentifier(),
            ]
        );
    }

    /**
     * @Route("/{localIdentifier}/remove-subscriber", name="member_email_remove")
     * @IsGranted("ROLE_EMAIL_MANAGER")
     */
    public function removeSubscriber(Member $member, EmailService $emailService): Response
    {
        if (!$emailService->isConfigured()) {
            $this->addFlash('danger', 'Email service not configured.');

            return $this->redirectToRoute('member_show', ['localIdentifier' => $member->getLocalIdentifier()]);
        }
        if ($emailService->unsubscribeMember($member)) {
            $this->addFlash('success', 'Subscriber record removed!');
        } else {
            $this->addFlash('danger', 'Unable to unsubscribe user.');
        }

        return $this->redirectToRoute(
            'member_email_subscription',
            [
                'localIdentifier' => $member->getLocalIdentifier(),
            ]
        );
    }

    /**
     * @Route("/{localIdentifier}/vcard", name="member_vcard")
     */
    public function generateVCard(Member $member): Response
    {
        // Create the VCard
        $vcard = new VCard([
            'FN' => $member->getDisplayName(),
            'N' => [$member->getLastName(), $member->getPreferredName()],
            'TITLE' => $member->getJobTitle(),
            'ORG' => $member->getEmployer(),
        ]);
        $vcard->add('TEL', $member->getPrimaryTelephoneNumber(), ['pref' => 1, 'type' => 'voice']);
        $vcard->add('EMAIL', $member->getPrimaryEmail(), ['pref' => 1]);
        $vcard->add(
            'ADR',
            [
                '',
                $member->getMailingAddressLine1(),
                $member->getMailingAddressLine2(),
                $member->getMailingCity(),
                $member->getMailingState(),
                $member->getMailingPostalCode(),
                $member->getMailingCountry(),
            ],
            [
                'pref' => 1,
                'type' => 'postal',
            ]
        );
        $vcard->validate(\Sabre\VObject\Node::REPAIR);
        $contentDisposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('%s.vcf', $member->getDisplayName())
        );

        return new Response($vcard->serialize(), 200, [
            'Content-type' => 'text/x-vcard; charset=utf-8',
            'Content-disposition' => $contentDisposition,
        ]);
    }

    /**
     * @Route("/{localIdentifier}/message", name="member_message")
     */
    public function message(Member $member, Request $request, EmailService $emailService, SmsService $smsService, CommunicationLogService $communicationLogService): Response
    {
        $formEmail = $this->createForm(MemberEmailType::class, null, ['acting_user' => $this->getUser()]);
        $formEmail->handleRequest($request);
        if ($formEmail->isSubmitted() && $formEmail->isValid()) {
            if (!$member->getPrimaryEmail()) {
                $this->addFlash('danger', 'No email set for member.');

                return $this->redirectToRoute('member_show', ['localIdentifier' => $member->getLocalIdentifier()]);
            }
            $formData = $formEmail->getData();
            $formData['subject'] = $this->formatMessage($formData['subject'], $member);
            $formData['message_body'] = $this->formatMessage($formData['message_body'], $member);
            try {
                $emailService->sendMemberEmail($formData, $member, $this->getUser());
                $this->addFlash('success', 'Email message sent!');
            } catch (\Exception $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        $formSMS = $this->createForm(MemberSMSType::class, null, ['acting_user' => $this->getUser()]);
        $formSMS->handleRequest($request);
        if ($formSMS->isSubmitted() && $formSMS->isValid()) {
            if (!$member->getPrimaryTelephoneNumber()) {
                $this->addFlash('danger', 'No telephone number set for member.');

                return $this->redirectToRoute('member_show', ['localIdentifier' => $member->getLocalIdentifier()]);
            }
            $formData = $formSMS->getData();
            $message = $this->formatMessage($formData['message_body'], $member);
            try {
                $smsService->sendMemberSms($message, $member, $this->getUser());
                $this->addFlash('success', 'SMS message sent!');
            } catch (\Exception $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('directory/message.html.twig', [
            'member' => $member,
            'recentCommunications' => $member->getCommunicationLogs()->slice(0, 10),
            'formEmail' => $formEmail->createView(),
            'fromEmailAddress' => $emailService->getFromEmailAddress(),
            'formSMS' => $formSMS->createView(),
            'fromTelephoneNumber' => $smsService->getFromTelephoneNumber(),
        ]);
    }

    /* Private Methods */

    private function formatMessage($content, Member $member): string
    {
        $content = preg_replace('/\[FirstName\]/i', $member->getFirstName(), $content);
        $content = preg_replace('/\[MiddleName\]/i', $member->getMiddleName(), $content);
        $content = preg_replace('/\[PreferredName\]/i', $member->getPreferredName(), $content);
        $content = preg_replace('/\[LastName\]/i', $member->getLastName(), $content);
        $content = preg_replace('/\[MailingAddressLine1\]/i', $member->getMailingAddressLine1(), $content);
        $content = preg_replace('/\[MailingAddressLine2\]/i', $member->getMailingAddressLine2(), $content);
        $content = preg_replace('/\[MailingCity\]/i', $member->getMailingCity(), $content);
        $content = preg_replace('/\[MailingState\]/i', $member->getMailingState(), $content);
        $content = preg_replace('/\[MailingPostalCode\]/i', $member->getMailingPostalCode(), $content);
        $content = preg_replace('/\[MailingCountry\]/i', $member->getMailingCountry(), $content);
        $content = preg_replace('/\[PrimaryEmail\]/i', $member->getPrimaryEmail(), $content);
        $content = preg_replace('/\[PrimaryTelephoneNumber\]/i', $member->getPrimaryTelephoneNumber(), $content);

        return $content;
    }
}
