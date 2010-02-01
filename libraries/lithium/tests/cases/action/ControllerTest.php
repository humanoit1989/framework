<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2010, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace lithium\tests\cases\action;

use \Exception;
use \lithium\action\Controller;
use \lithium\tests\mocks\action\MockPostsController;
use \lithium\tests\mocks\action\MockControllerRequest;

class ControllerTest extends \lithium\test\Unit {

	/**
	 * Tests that render settings and dynamic class dependencies can properly be injected into the
	 * controller through the constructor.
	 *
	 * @return void
	 */
	public function testConstructionWithCustomProperties() {
		$postsController = new MockPostsController();

		$result = $postsController->access('_render');
		$this->assertIdentical($result['layout'], 'default');

		$result = $postsController->access('_classes');
		$this->assertIdentical($result['response'], '\lithium\action\Response');

		$postsController = new MockPostsController(array(
			'render' => array('layout' => false),
			'classes' => array('response' => '\app\extensions\net\http\Response')
		));

		$result = $postsController->access('_render');
		$this->assertIdentical($result['layout'], false);

		$result = $postsController->access('_classes');
		$this->assertIdentical($result['response'], '\app\extensions\net\http\Response');
	}

	/**
	 * Tests that controllers can be instantiated with custom request objects.
	 *
	 * @return void
	 */
	public function testConstructionWithCustomRequest() {
		$request = new MockControllerRequest();
		$postsController = new MockPostsController(compact('request'));
		$result = get_class($postsController->request);
		$this->assertEqual($result, 'lithium\tests\mocks\action\MockControllerRequest');
	}

	/**
	 * Tests the use of `Controller::__invoke()` for dispatching requests to action methods.  Also
	 * tests that using PHP's callable syntax yields the same result as calling `__invoke()`
	 * explicitly.
	 *
	 * @return void
	 */
	public function testMethodInvocation() {
		$postsController = new MockPostsController();
		$result = $postsController->__invoke(null, array('action' => 'index', 'args' => array()));

		$this->assertTrue(is_a($result, 'lithium\action\Response'));
		$this->assertEqual($result->body(), 'List of posts');

		$headers = array('Content-type' => 'text/plain');
		$this->assertEqual($result->headers, $headers);

		$result2 = $postsController(null, array('action' => 'index', 'args' => array()));
		$this->assertEqual($result2, $result);

		$postsController = new MockPostsController();
		$this->expectException('/Template not found/');
		$result = $postsController->__invoke(null, array(
			'action' => 'index', 'args' => array(true)
		));

		$this->assertTrue(is_a($result, 'lithium\action\Response'));
		$this->assertEqual($result->body, '');

		$headers = array('Content-type' => 'text/html');
		$this->assertEqual($result->headers, $headers);

		$result = $postsController->access('_render');
		$this->assertEqual($result['data'], array('foo' => 'bar'));

		$postsController = new MockPostsController();
		$result = $postsController(null, array('action' => 'view', 'args' => array('2')));

		$this->assertTrue(is_a($result, 'lithium\action\Response'));
		$this->assertEqual($result->body, "Array\n(\n    [0] => This is a post\n)\n");

		$headers = array('status' => 200, 'Content-type' => 'text/plain');
		$this->assertEqual($result->headers(), $headers);

		$result = $postsController->access('_render');
		$this->assertEqual($result['data'], array('This is a post'));
	}

	/**
	 * Tests that calls to `Controller::redirect()` correctly write redirect headers to the
	 * response object.
	 *
	 * @return void
	 */
	public function testRedirectResponse() {
		$postsController = new MockPostsController();

		$result = $postsController->__invoke(null, array('action' => 'delete'));
		$this->assertEqual($result->body(), '');

		$headers = array('Location' => '/posts');
		$this->assertEqual($result->headers, $headers);

		$postsController = new MockPostsController();
		$result = $postsController(null, array('action' => 'delete', 'args' => array('5')));

		$this->assertEqual($result->body(), 'Deleted 5');
		$this->assertFalse($postsController->stopped);

		$postsController = new MockPostsController(array('classes' => array(
			'response' => '\lithium\tests\mocks\action\MockControllerResponse'
		)));
		$this->assertFalse($postsController->stopped);

		$postsController->__invoke(null, array('action' => 'send'));
		$this->assertTrue($postsController->stopped);

		$result = $postsController->access('_render');
		$this->assertTrue($result['hasRendered']);

		$this->assertEqual($postsController->response->body(), null);
		$this->assertEqual(
			$postsController->response->headers,
			array('Location' => '/posts')
		);
	}

	/**
	 * Tests calling `Controller::render()` with parameters to render an alternate template from
	 * the default.
	 *
	 * @return void
	 */
	public function testRenderWithAlternateTemplate() {
		$postsController = new MockPostsController(array('classes' => array(
			'media' => '\lithium\tests\mocks\action\MockMediaClass'
		)));

		$result = $postsController(null, array('action' => 'view2'));
		$this->assertEqual('view', $result->options['template']);
		$this->assertEqual('default', $result->options['layout']);

		$result = $postsController(null, array('action' => 'view3'));
		$this->assertEqual('view', $result->options['template']);
		$this->assertFalse($result->options['layout']);
	}

	/**
	 * Verifies that protected methods (i.e. prefixed with '_'), and methods declared in the
	 * Controller base class cannot be accessed.
	 *
	 * @return void
	 */
	public function testProtectedMethodAccessAttempt() {
		$postsController = new MockPostsController();
		$this->expectException('/^Private/');
		$result = $postsController->__invoke(null, array('action' => 'redirect'));

		$this->assertEqual($result->body, null);
		$this->assertEqual($result->headers(), array());

		$postsController = new MockPostsController();
		$this->expectException('/^Private/');
		$result = $postsController->invoke('_safe');

		$this->assertEqual($result->body, null);
		$this->assertEqual($result->headers(), array());
	}

	public function testResponseStatus() {
		$postsController = new MockPostsController(array('classes' => array(
			'response' => '\lithium\tests\mocks\action\MockControllerResponse'
		)));
		$this->assertFalse($postsController->stopped);

		$postsController->__invoke(null, array('action' => 'not_found'));

		$result = $postsController->access('_render');
		$this->assertTrue($result['hasRendered']);

		$expected = array('code' => 404, 'message' => 'Not Found');
		$result = $postsController->response->status;
		$this->assertEqual($expected, $result);
		$result = json_decode($postsController->response->body(), true);
		$this->assertEqual($expected, $result);
	}

	public function testResponseTypeBasedOnRequestType() {
		$request = new MockControllerRequest(array('type' => 'json'));

		$postsController = new MockPostsController(array(
			'request' => $request,
			'classes' => array(
				'response' => '\lithium\tests\mocks\action\MockControllerResponse'
			)
		));
		$this->assertFalse($postsController->stopped);

		$postsController->__invoke($request, array('action' => 'type'));

		$expected = array(
			'type' => 'json', 'data' => array('data' => 'test'), 'auto' => true,
			'layout' => 'default', 'template' => 'type', 'hasRendered' => true
		);
		$result = $postsController->access('_render');
		$this->assertEqual($expected, $result);

		$expected = 'application/json';
		$result = $postsController->response->headers('Content-type');
		$this->assertEqual($expected, $result);

		$expected = array('data' => 'test');
		$result = json_decode($postsController->response->body(), true);
		$this->assertEqual($expected, $result);
	}
}

?>