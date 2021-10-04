<?php

namespace OCA\Appointments\Tests\Unit;

use ErrorException;
use OCA\Appointments\AppInfo\Application;
use OCA\Appointments\Backend\BackendManager;
use OCA\Appointments\Backend\BackendUtils;
use OCA\Appointments\Controller\CalendarsController;
use OCA\Appointments\Controller\PageController;
use OCA\Appointments\Controller\StateController;
use OCA\Appointments\SendDataResponse;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Mail\IMailer;
use OCP\PreConditionNotMetException;
use PHPUnit\Framework\TestCase;
use OCA\Appointments\Tests\TestConstants;
use Psr\Log\Test\TestLogger;


class RemindersTest extends TestCase
{
    const SC_ERR = 'bad StateController status';

    private $utils;
    private $config;
    private $userId;
    private $l10n;
    private $backendManager;
    private $backendConnector;
    private $mailer;
    private $logger;

    private $attendeeEmail;
    private $testCalId;

    protected function setUp(): void {
        parent::setUp();

        $this->userId = TestConstants::USER_ID;

        $app = new Application();

        $this->logger = new TestLogger();

        $container = $app->getContainer();

        $this->config = $container->get(IConfig::class);
        $this->l10n = $container->get(IL10N::class);
        $this->mailer = $container->get(IMailer::class);

        $db = $container->get(IDBConnection::class);
        $this->utils = new BackendUtils($this->logger, $db);

        $dav = new \OCA\DAV\AppInfo\Application();
        $dav->getContainer()->get(CalDavBackend::class);
        $this->backendManager = new BackendManager();
        $this->backendConnector = $this->backendManager->getConnector();

        $this->attendeeEmail = getenv('TEST_ATTENDEE_EMAIL');
        $this->assertNotEquals(false, $this->attendeeEmail, "missing TEST_ATTENDEE_EMAIL environment var");
        $this->consoleLog($this->attendeeEmail);

        $this->checkSetup();
    }

    function testReminders() {

        $now = time() + 3600;

        // delete old appointments
        $cdt = new \DateTime();
        $cdt->modify("+45 days");

        $cr = $this->backendConnector->queryRangePast([$this->testCalId], $cdt, false, true);
        $this->consoleLog($cr);


        // update template
        $this->setTemplateFromTimestamps([$now, $now + 86400, $now + 2 * 86400]);

        // create actual appointments
        $this->createAppointmentsForReminders();

        // set reminders
        $allowed_seconds = ["3600", "7200", "14400", "28800", "86400", "172800", "259200", "345600", "432000", "518400", "604800"];
        $rd = [
            BackendUtils::REMINDER_DATA => [
                [
                    BackendUtils::REMINDER_DATA_TIME => $allowed_seconds[0],
                    BackendUtils::REMINDER_DATA_ACTIONS => true
                ],
                [
                    BackendUtils::REMINDER_DATA_TIME => $allowed_seconds[4],
                    BackendUtils::REMINDER_DATA_ACTIONS => true
                ],
                [
                    BackendUtils::REMINDER_DATA_TIME => $allowed_seconds[10],
                    BackendUtils::REMINDER_DATA_ACTIONS => true
                ],
            ],
            BackendUtils::REMINDER_MORE_TEXT => "test more reminder text"
        ];
        $r = $this->callStateController([
            'a' => 'set_reminder',
            'd' => json_encode($rd)
        ]);
        $this->assertEquals(200, $r->getStatus(), self::SC_ERR);


    }


    function createAppointmentsForReminders() {

        $cls = $this->utils->getUserSettings(BackendUtils::KEY_CLS, $this->userId);

        $utz = $this->utils->getUserTimezone($this->userId, $this->config);
        $t_start = new \DateTime('now +' . $cls[BackendUtils::CLS_PREP_TIME] . "mins", $utz);

        // + one week
        $t_end = clone $t_start;
        $t_end->setTimestamp($t_start->getTimestamp() + (7 * 86400));
        $t_end->setTime(0, 0);

        $out = $this->backendConnector->queryTemplate(
            $cls, $t_start, $t_end, $this->userId, TestConstants::PAGE0);
        $this->assertNotEquals(null, $out, "no dates");

        $outArr = explode(',', $out);
        $this->consoleLog($outArr);

        $oac = count($outArr);

        for ($i = 0; $i < $oac; $i++) {

            $outParts = explode(':', $outArr[$i]);
            $pc = $this->getPageController([
                'adatetime' => $outParts[2],
                'appt_dur' => 0,
                'name' => 'Test name 1',
                'email' => $this->attendeeEmail,
                'phone' => '1234567890',
                'tzi' => 'T' . 'America/New_York'
            ]);

            $rr = $pc->showFormPost($this->userId, TestConstants::PAGE0);
            $this->assertEquals(303, $rr->getStatus(), self::SC_ERR);

            $rUrl = $rr->getRedirectURL();
            $this->assertNotEquals('form?sts=1', $rUrl, "bad input");

            $this->consoleLog($rUrl);

            $uParts = explode('/', $rUrl);
            $c = count($uParts);
            $pc = $this->getPageController([
                'token' => urldecode($uParts[$c - 2]),
                'd' => urldecode(explode('=', $uParts[$c - 1])[1])
            ]);
            $res = $pc->cncf();
            $this->assertEquals(200, $res->getStatus(), self::SC_ERR);

            $this->consoleLog($res->getParams());
        }
    }


    function setTemplateFromTimestamps(array $timestamps) {
        $tz = $this->utils->getUserTimezone($this->userId, $this->config);
        $this->consoleLog("tz name: " . $tz->getName());

        $dt = new \DateTime();
        $dt->setTimezone($tz);
        $tar = [[], [], [], [], [], [], []];
        foreach ($timestamps as $ts) {
            $dt->setTimestamp($ts);
            $weekDay = $dt->format("N") - 1;
            $hours = +$dt->format("G");
            $minutes = (int)$dt->format("i");
            $tar[$weekDay][] = [
                "start" => $hours * 3600 + $minutes * 60,
                "dur" => [15, 45],
                "title" => "test_appointment"
            ];
        }
        $r = $this->callStateController([
            'a' => 'set_t_data',
            'p' => TestConstants::PAGE0,
            'd' => json_encode($tar)
        ]);
        $this->assertEquals(200, $r->getStatus(), self::SC_ERR);
    }


    function checkSetup() {

        $r = $this->callStateController(['a' => 'get_uci']);
        $this->assertEquals(200, $r->getStatus(), self::SC_ERR);
        $uci = json_decode($r->render(), true);
        $this->assertNotEquals(false, $uci);
        $this->assertNotEmpty($uci[BackendUtils::ORG_EMAIL], "organization email is not set");

        $cals = $this->getCalList();
        $this->testCalId = "-1";
        foreach ($cals as $cal) {
            if ($cal['displayName'] === TestConstants::USER_TEMPLATE_CALENDAR) {
                $this->testCalId = $cal['id'];
                break;
            }
        }
        $this->assertNotEquals('-1', $this->testCalId, "test calendar is missing");

        $this->consoleLog(TestConstants::USER_TEMPLATE_CALENDAR . " id: " . $this->testCalId);


        $r = $this->callStateController(['a' => 'get_cls']);
        $this->assertEquals(200, $r->getStatus(), self::SC_ERR);
        $cls = json_decode($r->render(), true);

        $this->assertNotEquals(false, $cls);
        $this->assertEquals($this->testCalId, $cls[BackendUtils::CLS_TMM_DST_ID], "bad main calendar id");
        $this->assertEquals(BackendUtils::CLS_TS_MODE_TEMPLATE, $cls[BackendUtils::CLS_TS_MODE], "use weekly template mode for tests");

        $r = $this->callStateController(['a' => 'get_eml']);
        $this->assertEquals(200, $r->getStatus(), self::SC_ERR);
        $eml = json_decode($r->render(), true);
        $this->assertNotEquals(false, $eml);
        $this->assertEquals(true, $eml[BackendUtils::EML_SKIP_EVS], "must skip email validation step");

    }

    /**
     * @throws PreConditionNotMetException
     * @throws ErrorException
     */
    function callStateController(array $data): SendDataResponse {
        $request = $this->createMock(IRequest::class);
        $request->method("getParam")->willReturnCallback(function ($key, $default = "") use ($data) {
            return $data[$key] ?? $default;
        });

        $c = new StateController(
            Application::APP_ID,
            $request,
            $this->userId,
            $this->config,
            $this->l10n,
            $this->utils,
            $this->backendManager
        );

        return $c->index();
    }

    function getPageController(array $data): PageController {

        $request = $this->createMock(IRequest::class);
        $request->method("getParams")->willReturnCallback(function () use ($data) {
            return $data;
        });
        $request->method("getParam")->willReturnCallback(function ($key, $default = "") use ($data) {
            return $data[$key] ?? $default;
        });

        return new PageController(
            Application::APP_ID,
            $request,
            $this->userId,
            $this->config,
            $this->mailer,
            $this->l10n,
            $this->backendManager,
            $this->utils,
            $this->logger
        );
    }

    function getCalList(): array {
        $c = new CalendarsController(
            Application::APP_ID,
            $this->createMock(IRequest::class),
            $this->userId,
            $this->config,
            $this->utils,
            $this->backendManager);
        $data = $c->callist();
        $this->assertNotEquals(false, $data);

        $calData = explode(chr(31), $data);
        $this->assertNotEmpty($calData, "user has no calendars");

        $c30 = chr(30);
        $cals = [];
        foreach ($calData as $cd) {
            $c = explode($c30, $cd);
            $cals[] = [
                'displayName' => $c[0],
                'id' => $c[2]
            ];
        }
        return $cals;
    }


    /** @param mixed $data */
    function consoleLog($data) {
        fwrite(STDOUT, var_export($data, true) . PHP_EOL);
    }
}
