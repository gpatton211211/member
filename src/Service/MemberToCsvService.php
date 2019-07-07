<?php

namespace App\Service;

use Doctrine\Common\Collections\ArrayCollection;
use League\Csv\Writer;

use App\Entity\Member;

class MemberToCsvService
{
    public function arrayToCsvString(array $members): string
    {
        $csvWriter = Writer::createFromString();
        $csvWriter->insertOne([
            'status',
            'firstName',
            'preferredName',
            'middleName',
            'lastName',
            'classYear',
            'localIdentifier',
            'localIdentifierShort',
            'externalIdentifier',
            'primaryTelephoneNumber',
            'mailingAddressLine1',
            'mailingAddressLine2',
            'mailingCity',
            'mailingState',
            'mailingPostalCode',
            'mailingCountry',
            'employer',
            'jobTitle',
            'occupation',
            'tags',
            'isDeceased',
            'isLost',
            'isLocalDoNotContact',
            'isExternalDoNotContact'
        ]);
        foreach ($members as $member) {
            $csvWriter->insertOne($this->memberToArray($member));
        }
        return $csvWriter->getContent();
    }

    private function memberToArray(Member $member)
    {
        return [
            $member->getStatus()->getLabel(),
            $member->getFirstName(),
            $member->getPreferredName(),
            $member->getMiddleName(),
            $member->getLastName(),
            $member->getClassYear(),
            $member->getLocalIdentifier(),
            $member->getLocalIdentifierShort(),
            $member->getExternalIdentifier(),
            $member->getPrimaryTelephoneNumber(),
            $member->getMailingAddressLine1(),
            $member->getMailingAddressLine2(),
            $member->getMailingCity(),
            $member->getMailingState(),
            $member->getMailingPostalCode(),
            $member->getMailingCountry(),
            $member->getEmployer(),
            $member->getJobTitle(),
            $member->getOccupation(),
            $member->getTagsAsCSV(),
            $member->getIsDeceased(),
            $member->getIsLost(),
            $member->getIsLocalDoNotContact(),
            $member->getIsExternalDoNotContact()
        ];
    }
}
