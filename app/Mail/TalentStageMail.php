<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class TalentStageMail extends Mailable
{
    public function __construct(public string $subjectLine, public string $messageBody) {}

    public function build()
    {
        return $this->subject($this->subjectLine)->view('emails.talent-stage', ['subject' => $this->subjectLine, 'body' => $this->messageBody])->text('emails.talent-stage-text', ['body' => $this->messageBody]);
    }
}
