<?php
// Usage:
// cv php:eval '$x = new CRM_Ccbeimporter_ImportCommitteeFuture(); $x->run("/tmp/TEST.csv");'

class CRM_Ccbeimporter_ImportCommitteeFuture {
  public function run(string $filePath) {
    [$headers, $rows] = $this->openCsv($filePath);

    foreach ($rows as $row) {
      $contactId = $this->getContactIdFromName($row['FirstName'], $row['LastName']);
      $this->addEmail($contactId, $row);
      $this->addRelationship($contactId, $row);
    }
  }

  private function getContactIdFromName(string $firstName, $lastName): int {
    echo "Importing {$firstName} {$lastName}\n";

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

  private function addEmail(int $contactId, array $row): void {
    $EMAIL_TYPE_WORK = 2;
    $EMAIL_TYPE_OTHER = 4;
    $primaryEmail = TRUE;

    // the Excel cell can contain multiple emails separated by semicolon
    $emailsToImportFullList = explode(';', str_replace(' ', '', $row['Email']));
    $emailsToImport = [];

    // get all emails for this contact
    $existingEmails = \Civi\Api4\Email::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->execute();

    // loop over existing emails and remove them from the list of emails to import
    $numEmails = 0;
    foreach ($existingEmails as $existingEmail) {
      $primaryEmail = FALSE;
      if (!in_array($existingEmail['email'], $emailsToImportFullList)) {
        $emailsToImport[] = $existingEmail['email'];
      }
    }

    foreach ($emailsToImport as $email) {
      \Civi\Api4\Email::create(FALSE)
        ->addValue('contact_id', $contactId)
        ->addValue('location_type_id', $primaryEmail ? $EMAIL_TYPE_WORK : $EMAIL_TYPE_OTHER)
        ->addValue('email', $email)
        ->execute();

      $primaryEmail = FALSE;
    }
  }

  private function addRelationship(int $contactId, array $row): void {
    $RELTYPE_EXPERT = 24;
    $RELTYPE_MAIL_RECEIPIENT = 23;
    $COMMITTEE_ID = 210;

    $relTypeId = $row['Relationship'] == 'Committee or Network Expert Member of' ? $RELTYPE_EXPERT : $RELTYPE_MAIL_RECEIPIENT;

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
      ->addValue('Committee_or_Network_Relationship_Details.Country', $this->getCountryId($row['Country']))
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