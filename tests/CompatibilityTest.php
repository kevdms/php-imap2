<?php

namespace Javanile\MysqlImport\Tests;

use Javanile\Imap2\MysqlImport;
use PHPUnit\Framework\TestCase;

class CompatibilityTest extends ImapTestCase
{
    public function testAppend()
    {
        $imap1 = imap_open($this->mailbox, $this->username, $this->password);
        $imap2 = imap2_open($this->mailbox, $this->username, $this->accessToken, OP_XOAUTH2);

        $check1 = imap_check($imap1);
        $check2 = imap2_check($imap2);
        $this->assertEquals($check1, $check2);

        $initialCount = $check2->Nmsgs;

        imap_append($imap1, $this->mailbox, $this->message);
        imap2_append($imap2, $this->mailbox, $this->message);

        $check1 = imap_check($imap1);
        $check2 = imap2_check($imap2);
        $this->assertEquals($check1, $check2);

        $finalCount = $check2->Nmsgs;

        imap_close($imap1);
        imap2_close($imap2);

        $this->assertEquals($initialCount + 2, $finalCount);
    }
}
