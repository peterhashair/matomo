<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\UsersManager\tests\System;

use Piwik\Date;
use Piwik\API\Request;
use Piwik\Piwik;
use Piwik\Plugins\UsersManager\API;
use Piwik\Plugins\UsersManager\Model;
use Piwik\Plugins\UsersManager\tests\Fixtures\ManyUsers;
use Piwik\Tests\Framework\TestCase\SystemTestCase;

/**
 * @group UsersManager
 * @group ApiTest
 * @group Plugins
 */
class ApiTest extends SystemTestCase
{
    /**
     * @var ManyUsers
     */
    public static $fixture = null; // initialized below class definition

    /**
     * @var API
     */
    private $api;

    /**
     * @var Model
     */
    private $model;

    public function setUp() : void
    {
        parent::setUp(); // TODO: Change the autogenerated stub

        $this->api = API::getInstance();
        $this->model = new Model();
    }

    /**
     * @dataProvider getApiForTesting
     */
    public function testApi($api, $params = array())
    {
        $apiId = implode('_', $params);
        $logins = array(
            'login1' => 'when_superuseraccess',
            'login2' => 'when_adminaccess',
            'login4' => 'when_viewaccess'
        );

        // login1 = super user, login2 = some admin access, login4 = only view access
        foreach ($logins as $login => $appendix) {
            $params['token_auth'] = self::$fixture->users[$login]['token'];
            $xmlFieldsToRemove    = array('date_registered', 'password', 'token_auth', 'ts_password_modified', 'ts_changes_viewed');

            $this->runAnyApiTest($api, $apiId . '_' . $appendix, $params, array('xmlFieldsToRemove' => $xmlFieldsToRemove));
        }
    }

    public function test_getUserPreference_loginIsOptional()
    {
        $response = Request::processRequest('UsersManager.getUserPreference', array(
            'preferenceName' => API::PREFERENCE_DEFAULT_REPORT
        ));
        $this->assertEquals('1', $response);

        $response = Request::processRequest('UsersManager.getUserPreference', array(
            'preferenceName' => API::PREFERENCE_DEFAULT_REPORT_DATE
        ));
        $this->assertEquals('yesterday', $response);
    }

    public function test_getUserPreference_loginCanBeSet()
    {
        $response = Request::processRequest('UsersManager.getUserPreference', array(
            'userLogin' => Piwik::getCurrentUserLogin(),
            'preferenceName' => API::PREFERENCE_DEFAULT_REPORT_DATE
        ));
        $this->assertEquals('yesterday', $response);

        // user not exists
        $response = Request::processRequest('UsersManager.getUserPreference', array(
            'userLogin' => 'foo',
            'preferenceName' => API::PREFERENCE_DEFAULT_REPORT_DATE
        ));
        $this->assertEquals('yesterday', $response);
    }

    public function getApiForTesting()
    {
        $apiToTest = array(
            array('UsersManager.getUsers'),
            array('UsersManager.getUsersLogin'),
            array('UsersManager.getUsersAccessFromSite', array('idSite' => 6)), // admin user has admin access for this
            array('UsersManager.getUsersAccessFromSite', array('idSite' => 3)), // admin user has only view access for this, should not see anything
            array('UsersManager.getUsersSitesFromAccess', array('access' => 'admin')),
            array('UsersManager.getUsersWithSiteAccess', array('idSite' => 3, 'access' => 'admin')),
            array('UsersManager.getUser', array('userLogin' => 'login1')),
            array('UsersManager.getUser', array('userLogin' => 'login2')),
            array('UsersManager.getUser', array('userLogin' => 'login4')),
            array('UsersManager.getUser', array('userLogin' => 'login6')),
        );

        return $apiToTest;
    }

    public function test_createAppSpecificTokenAuth()
    {
        $this->model->deleteAllTokensForUser('login1');
        $token = $this->api->createAppSpecificTokenAuth('login1', 'password', 'test');
        $this->assertMd5($token);

        $user = $this->model->getUserByTokenAuth($token);
        $this->assertSame('login1', $user['login']);
    }

    public function test_createAppSpecificTokenAuth_canLoginByEmail()
    {
        $this->model->deleteAllTokensForUser('login1');
        $token = $this->api->createAppSpecificTokenAuth('login1@example.com', 'password', 'test');
        $this->assertMd5($token);

        $user = $this->model->getUserByTokenAuth($token);
        $this->assertSame('login1', $user['login']);
    }

    public function test_createAppSpecificTokenAuth_failsWhenPasswordNotValid()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The current password you entered is not correct.');

        $this->model->deleteAllTokensForUser('login1');
        $this->api->createAppSpecificTokenAuth('login1', 'foooooo', 'test');
    }

    public function test_createAppSpecificTokenAuth_withExpireDate()
    {
        $this->model->deleteAllTokensForUser('login1');
        $token = $this->api->createAppSpecificTokenAuth('login1', 'password', 'test', '2026-01-02 03:04:05');
        $this->assertMd5($token);

        $tokens = $this->model->getAllNonSystemTokensForLogin('login1');
        $this->assertEquals($this->model->hashTokenAuth($token), $tokens[0]['password']);
        $this->assertEquals('login1', $tokens[0]['login']);
        $this->assertEquals('test', $tokens[0]['description']);
        $this->assertEquals('2026-01-02 03:04:05', $tokens[0]['date_expired']);
    }

    public function test_createAppSpecificTokenAuth_withExpireHours()
    {
        $expireInHours = 48;
        $this->model->deleteAllTokensForUser('login1');
        $token = $this->api->createAppSpecificTokenAuth('login1', 'password', 'test', null, $expireInHours);
        $this->assertMd5($token);

        $tokens = $this->model->getAllNonSystemTokensForLogin('login1');
        $this->assertEquals($this->model->hashTokenAuth($token), $tokens[0]['password']);
        $this->assertEquals('login1', $tokens[0]['login']);
        $this->assertNotEmpty($tokens[0]['date_expired']);

        $dateExpired = Date::factory($tokens[0]['date_expired']);
        $dateExpired->isLater(Date::now()->addHour($expireInHours - 1 ));
        $dateExpired->isEarlier(Date::now()->addHour($expireInHours + 1));
    }

    private function assertMd5($string)
    {
        $this->assertSame(32, strlen($string));
        $this->assertTrue(ctype_xdigit($string));
    }

    public static function getOutputPrefix()
    {
        return '';
    }

    public static function getPathToTestDirectory()
    {
        return dirname(__FILE__);
    }

}

ApiTest::$fixture = new ManyUsers(1, 1, false);
