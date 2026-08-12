<?php
// Usage:
// cv php:eval '$x = new CRM_Ccbeimporter_ImportCommitteeFuture(); $x->run("/tmp/TEST.csv");'

class CRM_Ccbeimporter_ImportCommitteeFuture {
  public function run(string $filePath) {
    [$headers, $rows] = $this->openCsv($filePath);

    foreach ($rows as $row) {
      $contactId = $this->getContactIdFromName($row['FirstName'], $row['LastName']);
      $this->addEmail($contactId, $row['Email']);
      $this->addRelationship($contactId, $row);
    }
  }

  private function getContactIdFromName(string $firstName, $lastName): int {
    // contact exists?
    $contact = \Civi\Api4\Contact::get(FALSE)
      ->addWhere('first_name', '=', $firstName)
      ->addWhere('last_name', '=', $lastName)
      ->execute()
      ->first();
    if ($contact) {
      // Yes!!!
      return $contact['id'];
    }

    // no, create contact
    $results = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', $firstName)
      ->addValue('last_name', $lastName)
      ->execute();
    return $results[0]['id'];
  }

  private function addEmail(int $contactId, string $email): void {
    $numEmails = 0;
    $EMAIL_TYPE_WORK = 2;
    $EMAIL_TYPE_OTHER = 4;

    $existingEmails = \Civi\Api4\Email::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->execute();
    foreach ($existingEmails as $existingEmail) {
      $numEmails++;
      if ($existingEmail['email'] == $email) {
        return; // exists
      }
    }

    // email does not exists: add it
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('location_type_id', $numEmails > 0 ? $EMAIL_TYPE_OTHER : $EMAIL_TYPE_WORK)
      ->addValue('email', $email)
      ->execute();
  }

  private function addRelationship(int $contactId, array $row): void {
    $RELTYPE_MEMBER = 18;
    $RELTYPE_FOLLOWER = 19;
    $COMMITTEE_ID = 210;

    $relTypeId = $row['InExtranet'] == 'Yes' ? $RELTYPE_MEMBER : $RELTYPE_FOLLOWER;

    $relationship = \Civi\Api4\Relationship::get(FALSE)
      ->addWhere('contact_id_a', '=', $contactId)
      ->addWhere('contact_id_b', '=', $COMMITTEE_ID)
      ->addWhere('relationship_type_id', '=', $relTypeId)
      ->execute()
      ->first();

    if ($relationship) {
      return;
    }

    \Civi\Api4\Relationship::create(FALSE)
      ->addValue('contact_id_a', $contactId)
      ->addValue('contact_id_b', $COMMITTEE_ID)
      ->addValue('relationship_type_id', $relTypeId)
      ->addValue('Committee_Member_Details.Country', $this->getCountryId($row['Country']))
      ->execute();
  }

  private function getCountryId(string $country): string {
    $contact = \Civi\Api4\Contact::get(FALSE)
      ->addSelect('id', 'display_name')
      ->addWhere('contact_sub_type', '=', 'Member_Country')
      ->addWhere('display_name', 'LIKE', "%$country%")
      ->execute()
      ->first();

    if ($contact) {
      return $contact['id'];
    }
    elseif ($country == 'Slovak Republic') {
      return 90;
    }
    else {
      echo "Country $country not found\n";
      exit;
    }
  }

  private function openCsv(string $filePath): array {
    $handle = fopen($filePath, 'r');
    if ($handle === FALSE) {
      throw new \Exception("Cannot open CSV file: $filePath");
    }

    $headers = fgetcsv($handle);
    if ($headers === FALSE || $headers === NULL) {
      fclose($handle);
      throw new \Exception("CSV file is empty or unreadable: $filePath");
    }

    // Strip UTF-8 BOM if needed
    if (isset($headers[0])) {
      $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
    }

    $headers[0] = trim($headers[0], '"');

    $generator = (function () use ($handle, $headers) {
      while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) !== count($headers)) {
          continue; // skip malformed rows
        }
        yield array_combine($headers, $row);
      }
      fclose($handle);
    })();

    return [$headers, $generator];
  }
}