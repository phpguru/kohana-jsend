<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Strict JSend (JSON) response format
 *
 * @author	Kemal Delalic <github.com/kemo>
 * @see		http://labs.omniti.com/labs/jsend
 */ 
class Kohana_JSend {

	const DEFAULT_ASSOC = FALSE;    // Default $assoc is FALSE
	const DEFAULT_DEPTH = 512;      // Default $depth is 512
	const DEFAULT_OPTIONS = 0;      // Default $options is 0

	// Status codes
	const ERROR		= 'error';      // Execution errors; exceptions, etc.
	const FAIL		= 'fail';       // App errors: validation etc.
	const SUCCESS	= 'success';    // Default status: everything seems to be OK

	// Release version
	const VERSION = '1.2.0';
	
	/**
	 * @var	array	Valid status types
	 */
	protected static $_status_types = array(
		JSend::ERROR,
		JSend::FAIL,
		JSend::SUCCESS,
	);
	
	/**
	 * Decodes a value to JSON 
	 * 
	 * This is a proxy method to json_decode() with proper exception handling
	 *
	 * @param	string	$json       The json string being decoded.
	 * @param	bool	$assoc      Convert the result into associative array?
	 * @param	int		$depth      User specified recursion depth.
	 * @param	int		$options    Bitmask of JSON decode options.
	 * @return	mixed	Decoded value
	 * @throws	JSend_Exception
	 */
	public static function decode($json, $assoc = NULL, $depth = NULL, $options = NULL)
	{
		if ($assoc === NULL)
		{
			$assoc = JSend::DEFAULT_ASSOC;
		}
		
		if ($depth === NULL)
		{
			$depth = JSend::DEFAULT_DEPTH;
		}
		
		if ($options === NULL)
		{
			$options = JSend::DEFAULT_OPTIONS;
		}
		
		$result = json_decode($json, $assoc, $depth, $options);
		
		$error = json_last_error();
		
		if ($error !== JSON_ERROR_NONE and $message = JSend_Exception::error_message($error))
		{
			throw new JSend_Exception($message, NULL, $error);
		}
		
		return $result;
	}
	
	/**
	 * Encodes a value to JSON
	 *
	 * This is a proxy method to json_encode with proper exception handling
	 * 
	 * @param	mixed	$value
	 * @param	int		$options bitmask
	 * @return	string	JSON encoded
	 * @throws	JSend_Exception
	 */
	public static function encode($value, $options = NULL)
	{
		if ($options === NULL)
		{
			$options = JSend::DEFAULT_OPTIONS;
		}
		
		// Encode the value to JSON and check for errors
		$result = json_encode($value, $options);
		
		/**
		 * Check if there were errors during encoding and throw an exception
		 */
		$error = json_last_error();
		
		if ($error !== JSON_ERROR_NONE and $message = JSend_Exception::error_message($error))
		{
			throw new JSend_Exception($message, NULL, $error);
		}
		
		return $result;
	}
	
	/**
	 * Factory method
	 *
	 * @param	array	$data	Initial data to set
	 */
	public static function factory(array $data = NULL)
	{
		return new JSend($data);
	}
	
	/**
	 * Default method for rendering objects into their values equivalent
	 *
	 * Override on app level for additional classes:
	 *
	 *		public static function object_values($object)
	 *		{
	 *			if ($object instanceof SomeClass)
	 *				return $object->some_method();
	 *			
	 *			return parent::object_values($object);
	 *		}
	 * 
	 * @param	object	$object
	 * @return	mixed	Value to encode (e.g. array, string, int)
	 */
	public static function object_values($object)
	{
		/**
		 * JsonSerializable
		 * @see http://php.net/JsonSerializable
		 */
		if ($object instanceof JsonSerializable)
			return $object->jsonSerialize();
		
		if ($object instanceof ArrayObject)
			return $object->getArrayCopy();
			
		if ($object instanceof ORM or $object instanceof AutoModeler)
			return $object->as_array();
			
		if ($object instanceof ORM_Validation_Exception)
			return $object->errors('');
			
		if ($object instanceof Database_Result)
		{
			// @todo	handle these
		}
		
		// If no matches, return the whole object
		return $object;
	}
	
	/**
	 * @var	int		Status code
	 */
	protected $_code;

	/**
	 * @var	array	Return data
	 */
	protected $_data = array();
	
	/**
	 * @var	int		Status message
	 */
	protected $_message;
	
	/**
	 * @var	string	Status (success, fail, error)
	 */
	protected $_status = JSend::SUCCESS;
	
	/**
	 * @param	array	initial array of data
	 */
	public function __construct(array $data = NULL)
	{
		if ($data !== NULL)
		{
			$this->set($data);
		}
	}
	
	/**
	 * Magic getter method
	 */
	public function __get($key)
	{
		if (array_key_exists($key, $this->_data))
			return $this->_data[$key];
		
		throw new JSend_Exception('Nonexisting data key requested: :key',
			array(':key' => $key));
	}
	
	/**
	 * Magic setter method
	 */
	public function __set($key, $value)
	{
		return $this->set($key, $value);
	}
	
	/**
	 * More magic: what happens when echoed or casted to string?
	 */
	public function __toString()
	{
		try
		{
			return $this->render();
		}
		catch (Exception $e)
		{
			ob_start();
			
			JSend_Exception::handler($e);

			return (string) ob_get_clean();
		}
	}
	
	/**
	 * Binds a param by reference
	 * 
	 * @chainable
	 * @param	string	key
	 * @param	mixed	var
	 * @return	object	$this
	 */
	public function bind($key, & $value)
	{
		$this->_data[$key] =& $value;
		
		return $this;
	}
	
	/**
	 * Data getter (Arr::path() enabled)
	 * 
	 * @param	string	$path to get, e.g. 'post.name'
	 * @param	mixed	$default value
	 * @return	mixed	Path value or $default (if path doesn't exist)
	 */
	public function get($path, $default = NULL)
	{
		return Arr::path($this->_data, $path, $default);
	}
	
	/**
	 * Sets a key => value pair or the whole data array
	 * 
	 * Example with callback (for setting objects):
	 * 
	 *		$jsend->set('foo', new Model_Bar, 'Foo::bar');
	 *
	 * @chainable
	 * @param	mixed	$key string or array of key => value pairs
	 * @param	mixed	$value to set (in case $key is int or string)
	 * @param	mixed	$callback to use (when setting objects)
	 * @return	JSend	$this
	 */
	public function set($key, $value = NULL, $callback = NULL)
	{
		/**
		 * If array passed, replace the whole data
		 */
		if (is_array($key))
		{
			$this->_data = $key;
			
			return $this;
		}
		
		/**
		 * Use callback filter to render objects
		 * - If no callback is specified, default_callback() will be used
		 * - If 3rd parameter is set to FALSE, object will be set without callback
		 */
		if (is_object($value) and $callback !== FALSE)
		{
			if ($callback === NULL)
			{
				$callback = $this->default_callback();
			}
			
			$this->_data[$key] = call_user_func($callback, $value);
		}
		else
		{
			$this->_data[$key] = $value;
		}
		
		return $this;
	}
	
	/**
	 * Returns a callable function for extracting object data
	 * 
	 * @return	callable
	 */
	public function default_callback()
	{
		return 'JSend::object_values';
	}
	
	/**
	 * Response code getter / setter
	 *
	 * @chainable
	 * @param	int		$code
	 * @return	mixed	$code on get / $this on set
	 */
	public function code($code = NULL)
	{
		if ($code === NULL)
			return $this->_code;
		
		$this->_code = (int) $code;
		
		return $this;
	}
	
	/**
	 * Data getter (whole data array) / proxy to set()
	 *
	 * @chainable
	 * @param	mixed	$key string or array of key => value pairs
	 * @param	mixed	$value to set (in case $key is int or string)
	 * @param	mixed	$callback to use for setting objects
	 * @return	mixed	$this on set, complete data array if $key is NULL
	 */
	public function data($key = NULL, $value = NULL, $callback = NULL)
	{
		// If key is empty, use as getter
		if ($key === NULL)
			return $this->_data;
			
		return $this->set($key, $value, $callback);
	}
	
	/**
	 * Response message getter / setter
	 * 
	 * [!!] This will set status to JSend::ERROR
	 * 
	 * @chainable
	 * @param	mixed	$message string or Exception object
	 * @param	array	$values 	to use for translation
	 * @return	mixed	$message 	on get // $this on set
	 */
	public function message($message = NULL, array $values = NULL)
	{
		if ($message === NULL)
			return $this->_message;
		
		if ($message instanceof Exception)
		{
			// If the code hasn't been set, use Exception code
			if ($this->_code === NULL and $code = $message->getCode())
			{
				$this->code($code);
			}
			
			$this->_message = __(':class: :message', array(
				':class' 	=> get_class($message),
				':message' 	=> $message->getMessage(),
			));
		}
		else
		{
			$this->_message = __($message, $values);
		}
		
		/**
		 * Set the status to error 
		 * (response message is used *only* for error responses)
		 */
		$this->_status = JSend::ERROR;
		
		return $this;
	}
	
	/**
	 * Response status getter / setter
	 *
	 * @chainable
	 * @param	string	$status
	 * @return	mixed	$status on get // $this on set
	 */
	public function status($status = NULL)
	{
		if ($status === NULL)
			return $this->_status;
		
		if ( ! in_array($status, JSend::$_status_types, TRUE))
		{
			throw new JSend_Exception('Status must be one of these: :statuses',
				array(':statuses' => implode(', ', JSend::$_status_types)));
		}
		
		$this->_status = $status;
		
		return $this;
	}
	
	/**
	 * Renders the current object into JSend (JSON) string
	 * 
	 * @see		http://php.net/json_encode#refsect1-function.json-encode-parameters
	 * @param	int		$encode_options	json_encode() options bitmask
	 * @return	string	JSON representation of current object
	 */
	public function render($encode_options = NULL)
	{
		$data = array(
			'status'	=> $this->_status,
			'data'		=> $this->_data,
		);
		
		/**
		 * Error response must contain status & message
		 * while code & data are optional
		 */
		if ($this->_status === JSend::ERROR)
		{
			$data['message'] = $this->_message;
			
			if ($this->_code !== NULL)
			{
				$data['code'] = $this->_code;
			}
			
			if (empty($data['data']))
			{
				unset($data['data']);
			}
		}
		
		try
		{
			$response = JSend::encode($data, $encode_options);
		}
		catch (JSend_Exception $e)
		{
			// If encoding failed, create a new JSend error object based on exception
			return JSend::factory()
				->message('JSON error: :error', array(':error' => $e->getMessage()))
				->code($e->getCode())
				->render();
		}
		
		return $response;
	}
	
	/**
	 * Sets the required HTTP Response headers and body.
	 *
	 * [!!] This is the last method you call because 
	 * 		*Response body is casted to string the moment it's set*
	 *
	 * Example action:
	 *
	 * 	JSend::factory()
	 * 		->data('posts', $posts)
	 * 		->status(JSend::SUCCESS)
	 *		->render_into($this->response);
	 *
	 * @param	Response	$response
	 * @param	int			$encode_options for json_encode()
	 * @return	void
	 */
	public function render_into(Response $response, $encode_options = NULL)
	{
		$response->body($this->render($encode_options))
			->headers('content-type','application/json')
			->headers('x-response-format','jsend'); 
	}
	
}
