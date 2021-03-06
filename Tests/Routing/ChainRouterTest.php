<?php

/**
 * Those tests are taken and adapted from symfony cmf routing.
 * See more @
 * https://github.com/symfony-cmf/RoutingBundle
 * https://github.com/symfony-cmf/Routing
 */

namespace Raindrop\RoutingBundle\Tests\Routing;

use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Request;

use Raindrop\RoutingBundle\Routing\ChainRouter;
use Raindrop\RoutingBundle\Tests\BaseTestCase;

class ChainRouterTest extends BaseTestCase
{
    public function setUp()
    {
        $this->router = new ChainRouter($this->getMock('Symfony\Component\HttpKernel\Log\LoggerInterface'));
        $this->context = $this->getMock('Symfony\\Component\\Routing\\RequestContext');
    }

    public function testPriority()
    {
        $this->assertEquals(array(), $this->router->all());

        list($low, $high) = $this->createRouterMocks();

        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $this->assertEquals(array(
            $high,
            $low,
        ), $this->router->all());
    }

    /**
     * Routers are supposed to be sorted only once.
     * This test will check that by trying to get all routers several times.
     *
     * @covers \Symfony\Cmf\Component\Routing\ChainRouter::sortRouters
     * @covers \Symfony\Cmf\Component\Routing\ChainRouter::all
     */
    public function testSortRouters()
    {
        list($low, $medium, $high) = $this->createRouterMocks();
        // We're using a mock here and not $this->router because we need to ensure that the sorting operation is done only once.
        $router = $this->buildMock('Raindrop\\RoutingBundle\\Routing\\ChainRouter', array('sortRouters'));
        $router
            ->expects($this->once())
            ->method('sortRouters')
            ->will(
                $this->returnValue(
                    array($high, $medium, $low)
                )
            )
        ;

        $router->add($low, 10);
        $router->add($medium, 50);
        $router->add($high, 100);
        $expectedSortedRouters = array($high, $medium, $low);
        // Let's get all routers 5 times, we should only sort once.
        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame($expectedSortedRouters, $router->all());
        }
    }

    /**
     * This test ensures that if a router is being added on the fly, the sorting is reset.
     *
     * @covers \Symfony\Cmf\Component\Routing\ChainRouter::sortRouters
     * @covers \Symfony\Cmf\Component\Routing\ChainRouter::all
     * @covers \Symfony\Cmf\Component\Routing\ChainRouter::add
     */
    public function testReSortRouters()
    {
        list($low, $medium, $high) = $this->createRouterMocks();
        $highest = clone $high;
        // We're using a mock here and not $this->router because we need to ensure that the sorting operation is done only once.
        $router = $this->buildMock('Raindrop\\RoutingBundle\\Routing\\ChainRouter', array('sortRouters'));
        $router
            ->expects($this->at(0))
            ->method('sortRouters')
            ->will(
                $this->returnValue(
                    array($high, $medium, $low)
                )
            )
        ;
        // The second time sortRouters() is called, we're supposed to get the newly added router ($highest)
        $router
            ->expects($this->at(1))
            ->method('sortRouters')
            ->will(
                $this->returnValue(
                    array($highest, $high, $medium, $low)
                )
            )
        ;

        $router->add($low, 10);
        $router->add($medium, 50);
        $router->add($high, 100);
        $this->assertSame(array($high, $medium, $low), $router->all());

        // Now adding another router on the fly, sorting must have been reset
        $router->add($highest, 101);
        $this->assertSame(array($highest, $high, $medium, $low), $router->all());
    }

    /**
     * context must be propagated to chained routers and be stored locally
     */
    public function testContext()
    {
        list($low, $high) = $this->createRouterMocks();

        $low
            ->expects($this->once())
            ->method('setContext')
            ->with($this->context)
        ;

        $high
            ->expects($this->once())
            ->method('setContext')
            ->with($this->context)
        ;

        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $this->router->setContext($this->context);
        $this->assertSame($this->context, $this->router->getContext());
    }

    /**
     * The first usable match is used, no further routers are queried once a match is found
     */
    public function testMatch()
    {
        $url = '/test';
        list($lower, $low, $high) = $this->createRouterMocks();

        $high
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\ResourceNotFoundException))
        ;
        $low
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->returnValue(array('test')))
        ;
        $lower
            ->expects($this->never())
            ->method('match');
        $this->router->add($lower, 5);
        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $result = $this->router->match('/test');
        $this->assertEquals(array('test'), $result);
    }

    /**
     * The first usable match is used, no further routers are queried once a match is found
     */
    public function testMatchRequest()
    {
        $url = '/test';
        list($lower, $low, $high) = $this->createRouterMocks();

        $highest = $this->getMock('Raindrop\\RoutingBundle\\Tests\\Routing\\RequestMatcher');

        $request = Request::create('/test');

        $highest
            ->expects($this->once())
            ->method('matchRequest')
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\ResourceNotFoundException))
        ;
        $high
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\ResourceNotFoundException))
        ;
        $low
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->returnValue(array('test')))
        ;
        $lower
            ->expects($this->never())
            ->method('match')
        ;

        $this->router->add($lower, 5);
        $this->router->add($low, 10);
        $this->router->add($high, 100);
        $this->router->add($highest, 200);

        $result = $this->router->matchRequest($request);
        $this->assertEquals(array('test'), $result);
    }

    /**
     * If there is a method not allowed but another router matches, that one is used
     */
    public function testMatchAndNotAllowed()
    {
        $url = '/test';
        list($low, $high) = $this->createRouterMocks();

        $high
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\MethodNotAllowedException(array())))
        ;
        $low
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->returnValue(array('test')))
        ;
        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $result = $this->router->match('/test');
        $this->assertEquals(array('test'), $result);
    }

    /**
     * If there is a method not allowed but another router matches, that one is used
     */
    public function testMatchRequestAndNotAllowed()
    {
        $url = '/test';
        list($low, $high) = $this->createRouterMocks();

        $high
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\MethodNotAllowedException(array())))
        ;
        $low
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->returnValue(array('test')))
        ;
        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $result = $this->router->matchRequest(Request::create('/test'));
        $this->assertEquals(array('test'), $result);
    }

    /**
     * @expectedException \Symfony\Component\Routing\Exception\ResourceNotFoundException
     */
    public function testMatchNotFound()
    {
        $url = '/test';
        list($low, $high) = $this->createRouterMocks();

        $high
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\ResourceNotFoundException))
        ;
        $low
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\ResourceNotFoundException))
        ;
        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $this->router->match('/test');
    }

    /**
     * @expectedException \Symfony\Component\Routing\Exception\ResourceNotFoundException
     */
    public function testMatchRequestNotFound()
    {
        $url = '/test';
        list($low, $high) = $this->createRouterMocks();

        $high
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\ResourceNotFoundException))
        ;
        $low
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\ResourceNotFoundException))
        ;
        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $this->router->matchRequest(Request::create('/test'));
    }

    /**
     * If any of the routers throws a not allowed exception and no other matches, we need to see this
     *
     * @expectedException \Symfony\Component\Routing\Exception\MethodNotAllowedException
     */
    public function testMatchMethodNotAllowed()
    {
        $url = '/test';
        list($low, $high) = $this->createRouterMocks();

        $high
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\MethodNotAllowedException(array())))
        ;
        $low
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\ResourceNotFoundException))
        ;
        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $this->router->match('/test');
    }

    /**
     * If any of the routers throws a not allowed exception and no other matches, we need to see this
     *
     * @expectedException \Symfony\Component\Routing\Exception\MethodNotAllowedException
     */
    public function testMatchRequestMethodNotAllowed()
    {
        $url = '/test';
        list($low, $high) = $this->createRouterMocks();

        $high
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\MethodNotAllowedException(array())))
        ;
        $low
            ->expects($this->once())
            ->method('match')
            ->with($url)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\ResourceNotFoundException))
        ;
        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $this->router->matchRequest(Request::create('/test'));
    }

    public function testGenerate()
    {
        $url = '/test';
        $name = 'test';
        $parameters = array('test' => 'value');
        list($lower, $low, $high) = $this->createRouterMocks();

        $high
            ->expects($this->once())
            ->method('generate')
            ->with($name, $parameters, false)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\RouteNotFoundException()))
        ;
        $low
            ->expects($this->once())
            ->method('generate')
            ->with($name, $parameters, false)
            ->will($this->returnValue($url))
        ;
        $lower
            ->expects($this->never())
            ->method('generate')
        ;

        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $result = $this->router->generate($name, $parameters);
        $this->assertEquals($url, $result);
    }

    /**
     * @expectedException \Symfony\Component\Routing\Exception\RouteNotFoundException
     */
    public function testGenerateNotFound()
    {
        $url = '/test';
        $name = 'test';
        $parameters = array('test' => 'value');
        list($low, $high) = $this->createRouterMocks();

        $high
            ->expects($this->once())
            ->method('generate')
            ->with($name, $parameters, false)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\RouteNotFoundException()))
        ;
        $low->expects($this->once())
            ->method('generate')
            ->with($name, $parameters, false)
            ->will($this->throwException(new \Symfony\Component\Routing\Exception\RouteNotFoundException()))
        ;
        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $result = $this->router->generate($name, $parameters);
        $this->assertEquals($url, $result);
    }

    public function testWarmup()
    {
        $dir = 'test_dir';
        list($low) = $this->createRouterMocks();

        $low
            ->expects($this->never())
            ->method('warmUp')
        ;
        $high = $this->getMock('Raindrop\\RoutingBundle\\Tests\\Routing\\WarmableRouterMock');
        $high
            ->expects($this->once())
            ->method('warmUp')
            ->with($dir)
        ;

        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $this->router->warmUp($dir);
    }

    public function testRouteCollection()
    {
        list($low, $high) = $this->createRouterMocks();
        $lowcol = new RouteCollection();
        $lowcol->add('low', $this->buildMock('Symfony\\Component\\Routing\\Route'));
        $highcol = new RouteCollection();
        $highcol->add('high', $this->buildMock('Symfony\\Component\\Routing\\Route'));

        $low
            ->expects($this->once())
            ->method('getRouteCollection')
            ->will($this->returnValue($lowcol))
        ;
        $high
            ->expects($this->once())
            ->method('getRouteCollection')
            ->will($this->returnValue($highcol))
        ;

        $this->router->add($low, 10);
        $this->router->add($high, 100);

        $collection = $this->router->getRouteCollection();
        $this->assertInstanceOf('Symfony\\Component\\Routing\\RouteCollection', $collection);

        $names = array();
        foreach ($collection->all() as $name => $route) {
            $this->assertInstanceOf('Symfony\\Component\\Routing\\Route', $route);
            $names[] = $name;
        }
        $this->assertEquals(array('high', 'low'), $names);
    }

    protected function createRouterMocks()
    {
        return array(
            $this->getMock('Symfony\\Component\\Routing\\RouterInterface'),
            $this->getMock('Symfony\\Component\\Routing\\RouterInterface'),
            $this->getMock('Symfony\\Component\\Routing\\RouterInterface'),
        );
    }
}

abstract class WarmableRouterMock implements \Symfony\Component\Routing\RouterInterface, \Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface
{
}

abstract class RequestMatcher implements \Symfony\Component\Routing\RouterInterface, \Symfony\Component\Routing\Matcher\RequestMatcherInterface
{
}
