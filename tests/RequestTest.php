<?php
namespace rockunit;


use rock\request\Request;
use rock\sanitize\Sanitize;

/**
 * @group base
 * @group request
 */
class RequestTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'] = 'site.com';
        $_SERVER['REQUEST_URI'] = '/foo/?page=1';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = '/var/www/index.php';
        $_SERVER['QUERY_STRING'] = 'page=1';
        $_SERVER['SERVER_PORT'] = 80;
        $_SERVER['HTTP_REFERER'] = 'http://referer.com/bar/';
        $_SERVER['HTTP_USER_AGENT'] = 'user';
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /** @var  Request */
    protected $request;
    protected function setUp()
    {
        parent::setUp();
        $this->request = $this->getRequest();
    }


    /**
     * @dataProvider httpMethodScalarProvider
     */
    public function testScalar($httpMethod, $method)
    {
        $GLOBALS[$httpMethod]['foo'] = ' <b>foo</b>     ';
        $GLOBALS[$httpMethod]['bar'] = '    <b>bar   </b>';
        $GLOBALS[$httpMethod]['baz'] = '    <b>-1</b>';
        $this->assertSame('<b>foo</b>', Request::$method('foo', null, Sanitize::trim()));
        $this->assertSame('bar', Request::$method('bar'));
        $this->assertSame(-1, Request::$method('baz'));
        $this->assertSame(0, Request::$method('baz', null, Sanitize::removeTags()->trim()->positive()));
    }

    public function httpMethodScalarProvider()
    {
        return [
            ['_GET', 'get'],
            ['_POST', 'post'],
            ['_PUT', 'put'],
            ['_DELETE', 'delete']
        ];
    }

    public function testScalarNull()
    {
        $this->assertNull(Request::get('unknown'));
    }

    /**
     * @dataProvider httpMethodAllProvider
     */
    public function testAll($httpMethod, $method)
    {
        $GLOBALS[$httpMethod]['foo'] = ' <b>foo</b>     ';
        $GLOBALS[$httpMethod]['bar'] = '    <b>bar   </b>';
        $this->assertEquals(['foo' => '<b>foo</b>', 'bar' =>'<b>bar   </b>', 'baz' => '<b>-1</b>'], Request::$method(Sanitize::trim()));
    }

    public function httpMethodAllProvider()
    {
        return [
            ['_GET', 'getAll'],
            ['_POST', 'postAll'],
            ['_PUT', 'putAll'],
            ['_DELETE', 'deleteAll']
        ];
    }

    public function testSanitize()
    {
        $_GET['foo'] = ' <b>foo</b>     ';
        $_GET['bar'] = '    <b>bar   </b>';
        $_GET['baz'] = '{"baz" : " <b> baz  </b>     "}';
        $result = Request::getAll(Sanitize::attributes(
            [
                'bar' => Sanitize::removeTags()->trim(),
                'baz' => Sanitize::unserialize()->removeTags()->trim(),
            ]
        ));
        $this->assertEquals(' <b>foo</b>     ', $result['foo']);
        $this->assertEquals('bar', $result['bar']);
        $this->assertEquals(['baz' => 'baz'], $result['baz']);
    }

    public function testAllAttributesTrim()
    {
        $_GET['foo'] = ' <b>foo</b>     ';
        $_GET['bar'] = '    <b>bar   </b>';
        $result = Request::getAll(Sanitize::trim());
        $this->assertEquals('<b>foo</b>', $result['foo']);
        $this->assertEquals('<b>bar   </b>', $result['bar']);
    }


    public function testAllAttributesUnserialize()
    {
        $_GET['foo'] = '{"foo" : "foo"}';
        $_GET['bar'] = '{"bar" : "bar"}';
        $result = Request::getAll(Sanitize::unserialize());
        $this->assertEquals(['foo' => 'foo'], $result['foo']);
        $this->assertEquals(['bar' => 'bar'], $result['bar']);
    }

    public function testUnserialize()
    {
        $_GET['foo'] = ' <b>foo</b>     ';
        $_GET['bar'] = '{"bar" : "bar"}';
        $result = Request::getAll(Sanitize::attributes(['bar' => Sanitize::unserialize()]));
        $this->assertEquals(' <b>foo</b>     ', $result['foo']);
        $this->assertEquals(['bar' => 'bar'], $result['bar']);
    }

    public function testNumeric()
    {
        $_GET['foo'] = '<b>-5.5</b>';
        $_GET['bar'] = '5.5';
        $_GET['baz'] = '{"baz" : "5.6"}';
        $result = Request::getAll(Sanitize::attributes(
            [
                'foo' => Sanitize::call('strip_tags')->call('abs')->call('ceil'),
                'bar' => Sanitize::call('floor'),
                'baz' => Sanitize::unserialize()->call('round'),
            ]
        ));
        $this->assertEquals(6, $result['foo']);
        $this->assertEquals(5, $result['bar']);
        $this->assertEquals(['baz' => 6], $result['baz']);
    }

    public function testPost()
    {
        $_POST['foo'] = '<b>foo</b>    ';
        $_POST['bar'] = ['foo' => ['  <b>foo</b>'], 'bar' => '{"baz" : "<b>bar</b>baz "}'];
        $_POST['baz'] = '{"foo" : "<b>foo</b>", "bar" : {"foo" : "<b>baz</b>   "}}';
        $_POST['test'] = serialize(['foo' => ['  <b>foo</b>'], 'bar' => '<b>bar</b>baz ']);
        $result = Request::postAll(Sanitize::allOf(Sanitize::unserialize()->removeTags()->trim()));
        $this->assertEquals('foo', $result['foo']);
        $this->assertEquals(['foo' => ['foo'], 'bar' => ['baz'=>'barbaz']], $result['bar']);
        $this->assertEquals(['foo' => 'foo', 'bar' => ['foo' => 'baz']],$result['baz']);
        $this->assertEquals(['foo' => ['foo'], 'bar' => 'barbaz'], $result['test']);
    }


    public function testParseAcceptHeader()
    {
        $request = new Request;

        $this->assertEquals([], $request->parseAcceptHeader(' '));

        $this->assertEquals([
                                'audio/basic' => ['q' => 1],
                                'audio/*' => ['q' => 0.2],
                            ], $request->parseAcceptHeader('audio/*; q=0.2, audio/basic'));

        $this->assertEquals([
                                'application/json' => ['q' => 1, 'version' => '1.0'],
                                'application/xml' => ['q' => 1, 'version' => '2.0', 'x'],
                                'text/x-c' => ['q' => 1],
                                'text/x-dvi' => ['q' => 0.8],
                                'text/plain' => ['q' => 0.5],
                            ], $request->parseAcceptHeader('text/plain; q=0.5,
            application/json; version=1.0,
            application/xml; version=2.0; x,
            text/x-dvi; q=0.8, text/x-c'));
    }

    public function testPreferredLanguage()
    {
        $request = $this->getRequest();
        $request->locale = 'en';
        $request->acceptableLanguages = [];
        $this->assertEquals('en', $request->getPreferredLanguage());

        $request = $this->getRequest();
        $request->acceptableLanguages = ['de'];
        $this->assertEquals('en', $request->getPreferredLanguage());

        $request = $this->getRequest();
        $request->acceptableLanguages = ['en-us', 'de', 'ru-RU'];
        $this->assertEquals('en', $request->getPreferredLanguage(['en']));

        $request = $this->getRequest();
        $request->acceptableLanguages = ['en-us', 'de', 'ru-RU'];
        $this->assertEquals('de', $request->getPreferredLanguage(['ru', 'de']));
        $this->assertEquals('de-DE', $request->getPreferredLanguage(['ru', 'de-DE']));

        $request = $this->getRequest();
        $request->acceptableLanguages = ['en-us', 'de', 'ru-RU'];
        $this->assertEquals('de', $request->getPreferredLanguage(['de', 'ru']));

        $request = $this->getRequest();
        $request->acceptableLanguages = ['en-us', 'de', 'ru-RU'];
        $this->assertEquals('ru-ru', $request->getPreferredLanguage(['ru-ru']));

        $request = $this->getRequest();
        $request->acceptableLanguages = ['en-us', 'de'];
        $this->assertEquals('ru-ru', $request->getPreferredLanguage(['ru-ru', 'pl']));
        $this->assertEquals('ru-RU', $request->getPreferredLanguage(['ru-RU', 'pl']));

        $request = $this->getRequest();
        $request->acceptableLanguages = ['en-us', 'de'];
        $this->assertEquals('pl', $request->getPreferredLanguage(['pl', 'ru-ru']));
    }

    public function testAcceptableContentTypes()
    {
        // empty HTTP_ACCEPT
        $request = $this->getRequest();
        $this->assertEmpty($request->getAcceptableContentTypes());

        $_SERVER['HTTP_ACCEPT'] = 'application/json, text/plain, */*';
        $request = $this->getRequest();
        $expected = [
            'application/json' =>
                [
                    'q' => 1,
                ],
            'text/plain' =>
                [
                    'q' => 1,
                ],
            '*/*' =>
                [
                    'q' => 1,
                ],
        ];
        $this->assertSame($expected, $request->getAcceptableContentTypes());

        // set
        $request->setAcceptableContentTypes($expected);
        $this->assertSame($expected, $request->getAcceptableContentTypes());
    }

    public function testGetContentType()
    {
        // empty CONTENT_TYPE
        $this->assertNull($this->request->getContentType());
    }

    public function testGetETags()
    {
        // empty HTTP_IF_NONE_MATCH
        $request = $this->getRequest();
        $this->assertEmpty($request->getETags());

        $_SERVER['HTTP_IF_NONE_MATCH'] = 'foo, bar';
        $request = $this->getRequest();
        $this->assertSame(['foo', 'bar'], $request->getETags());
    }

    public function testGetAndSetPathInfo()
    {
        $this->assertSame('foo/', $this->request->getPathInfo());

        // set
        $this->request->setPathInfo('foo/');
        $this->assertSame('foo/', $this->request->getPathInfo());
    }

    public function testGetAbsoluteUrl()
    {
        $this->assertSame('http://site.com/foo/?page=1', $this->request->getAbsoluteUrl());
    }

    public function testGetUrlWithoutArgs()
    {
        $this->assertSame('http://site.com/foo/', $this->request->getUrlWithoutArgs());
    }

    public function testGetAndSetUrl()
    {
        $this->assertSame('/foo/?page=1', $this->request->getUrl());

        $this->request->url = '/bar/?page=1';
        $this->assertSame('/bar/?page=1', $this->request->getUrl());
    }

    public function testGetSchema()
    {
        $this->assertSame('http', $this->request->getScheme());
    }

    public function testGetHost()
    {
        $this->assertSame('site.com', $this->request->host);
    }

    public function testGetAndSetBaseUrl()
    {
        $this->request->baseUrl = 'foo';
        $this->assertSame('foo', $this->request->baseUrl);
    }

    public function testGetAndSetScriptUrl()
    {
        $this->request->setScriptUrl('bar');
        $this->assertSame('/bar', $this->request->getScriptUrl());
    }

    public function testGetQueryString()
    {
        $this->assertSame('page=1', $this->request->queryString);
    }

    public function testGetServerName()
    {
        $this->assertSame('site.com', $this->request->serverName);
    }

    public function testGetServerPort()
    {
        $this->assertSame(80, $this->request->serverPort);
    }

    public function testGetReferrer()
    {
        $this->assertSame('http://referer.com/bar/', $this->request->referrer);
    }

    public function testGetUserAgent()
    {
        $this->assertSame('user', $this->request->userAgent);
    }

    public function testGetAndSetPort()
    {
        $this->assertSame(80, $this->request->port);

        // set
        $this->request->port = 443;
        $this->assertSame(443, $this->request->port);
    }

    public function testGetAndSetHomeUrl()
    {
        $this->assertSame('/index.php', $this->request->homeUrl);

        $this->request->homeUrl = '/main.php';
        $this->assertSame('/main.php', $this->request->homeUrl);
    }

    public function testGetAndSetSecurePort()
    {
        $this->assertSame(443, $this->request->getSecurePort());

        $this->request->securePort = 444;
        $this->assertSame(444, $this->request->getSecurePort());
    }

    public function testGetMethod()
    {
        $this->assertSame('GET', $this->request->getMethod());
        $this->assertTrue($this->request->isGet());

        $_POST[$this->request->methodVar] = 'POST';
        $this->assertSame('POST', $this->request->getMethod());
        $this->assertFalse($this->request->isGet());
        $this->assertTrue($this->request->isPost());
    }

    protected function getRequest()
    {
        return new Request();
    }
}