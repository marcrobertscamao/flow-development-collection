<?php
namespace Neos\Flow\Mvc\Controller;

/*
 * This file is part of the Neos.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Neos\Error\Messages as Error;
use Neos\Error\Messages\Result;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Exception\ForwardException;
use Neos\Flow\Mvc\Exception\InvalidActionVisibilityException;
use Neos\Flow\Mvc\Exception\InvalidArgumentTypeException;
use Neos\Flow\Mvc\Exception\NoSuchActionException;
use Neos\Flow\Mvc\Exception\NoSuchArgumentException;
use Neos\Flow\Mvc\Exception\RequiredArgumentMissingException;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Mvc\Exception\ViewNotFoundException;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Mvc\ViewConfigurationManager;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Property\Exception;
use Neos\Flow\Property\Exception\TargetNotFoundException;
use Neos\Flow\Property\TypeConverter\Error\TargetNotFoundError;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Utility\TypeHandling;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * An HTTP based multi-action controller.
 *
 * The action specified in the given ActionRequest is dispatched to a method in
 * the concrete controller whose name ends with "*Action". If no matching action
 * method is found, the action specified in $errorMethodName is invoked.
 *
 * This controller also takes care of mapping arguments found in the ActionRequest
 * to the corresponding method arguments of the action method. It also invokes
 * validation for these arguments by invoking the Property Mapper.
 *
 * By defining media types in $supportedMediaTypes, content negotiation based on
 * the browser's Accept header and additional routing configuration is used to
 * determine the output format the controller should return.
 *
 * Depending on the action being called, a fitting view - determined by configuration
 * - will be selected. By specifying patterns, custom view classes or an alternative
 * controller / action to template path mapping can be defined.
 *
 * @api
 */
class ActionController extends AbstractController
{
    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\Inject
     * @var ReflectionService
     */
    protected $reflectionService;

    /**
     * @Flow\Inject
     * @var MvcPropertyMappingConfigurationService
     */
    protected $mvcPropertyMappingConfigurationService;

    /**
     * @Flow\Inject
     * @var ViewConfigurationManager
     */
    protected $viewConfigurationManager;

    /**
     * The current view, as resolved by resolveView()
     *
     * @var ViewInterface
     * @api
     */
    protected $view = null;

    /**
     * Pattern after which the view object name is built if no format-specific
     * view could be resolved.
     *
     * @var string
     * @api
     */
    protected $viewObjectNamePattern = '@package\View\@controller\@action@format';

    /**
     * A list of formats and object names of the views which should render them.
     *
     * Example:
     *
     * array('html' => 'MyCompany\MyApp\MyHtmlView', 'json' => 'MyCompany\...
     *
     * @var array
     */
    protected $viewFormatToObjectNameMap = [];

    /**
     * The default view object to use if none of the resolved views can render
     * a response for the current request.
     *
     * @var string
     * @api
     */
    protected $defaultViewObjectName = null;

    /**
     * @Flow\InjectConfiguration(package="Neos.Flow", path="mvc.view.defaultImplementation")
     * @var string
     */
    protected $defaultViewImplementation;

    /**
     * Name of the action method
     *
     * @var string
     */
    protected $actionMethodName;

    /**
     * Name of the special error action method which is called in case of errors
     *
     * @var string
     * @api
     */
    protected $errorMethodName = 'errorAction';

    /**
     * @var array
     */
    protected $settings;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ThrowableStorageInterface
     */
    private $throwableStorage;

    /**
     * Feature flag to enable the potentially breaking support of validation for dynamic types specified with `__type` argument or in the `PropertyMapperConfiguration`.
     * Note: This will be enabled by default in a future version.
     * See https://github.com/neos/flow-development-collection/pull/1905
     * @var boolean
     */
    protected $enableDynamicTypeValidation = false;

    /**
     * @param array $settings
     * @return void
     */
    public function injectSettings(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Injects the (system) logger based on PSR-3.
     *
     * @param LoggerInterface $logger
     * @return void
     */
    public function injectLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Injects the throwable storage.
     *
     * @param ThrowableStorageInterface $throwableStorage
     * @return void
     */
    public function injectThrowableStorage(ThrowableStorageInterface $throwableStorage)
    {
        $this->throwableStorage = $throwableStorage;
    }

    /**
     * Handles a request. The result output is returned by altering the given response.
     *
     * @param ActionRequest $request The request object
     * @return ResponseInterface
     * @throws InvalidActionVisibilityException
     * @throws InvalidArgumentTypeException
     * @throws NoSuchActionException
     * @throws StopActionException
     * @throws ForwardException
     * @throws ViewNotFoundException
     * @throws NoSuchArgumentException
     * @throws Exception
     * @throws \Neos\Flow\Security\Exception
     * @api
     */
    public function processRequest(ActionRequest $request): ResponseInterface
    {
        $response = new ActionResponse();
        $this->initializeController($request, $response);

        $this->actionMethodName = $this->resolveActionMethodName($request);

        $this->initializeActionMethodArguments($this->arguments);
        if ($this->enableDynamicTypeValidation !== true) {
            $this->initializeActionMethodValidators($this->arguments);
        }

        $this->initializeAction();
        $actionInitializationMethodName = 'initialize' . ucfirst($this->actionMethodName);
        if (method_exists($this, $actionInitializationMethodName)) {
            call_user_func([$this, $actionInitializationMethodName]);
        }

        $this->mvcPropertyMappingConfigurationService->initializePropertyMappingConfigurationFromRequest($request, $this->arguments);

        try {
            $this->mapRequestArgumentsToControllerArguments($request, $this->arguments);
        } catch (RequiredArgumentMissingException $e) {
            $message = $this->throwableStorage->logThrowable($e);
            $this->logger->notice('Request argument mapping failed due to a missing required argument. ' . $message, LogEnvironment::fromMethodName(__METHOD__));
            $this->throwStatus(400, null, 'Required argument is missing');
        }
        if ($this->enableDynamicTypeValidation === true) {
            $this->initializeActionMethodValidators($this->arguments);
        }

        if ($this->view === null) {
            $this->view = $this->resolveView($request);
        }
        if ($this->view !== null) {
            $this->view->assign('settings', $this->settings);
            $this->view->assign('request', $this->request);
            if (method_exists($this->view, 'setControllerContext')) {
                $this->view->setControllerContext($this->controllerContext);
            }
            $this->initializeView($this->view);
        }

        $httpResponse = $this->callActionMethod($request, $this->arguments, $response);

        if (!$httpResponse->hasHeader('Content-Type')) {
            $httpResponse = $httpResponse->withHeader('Content-Type', $this->negotiatedMediaType);
        }

        return $httpResponse;
    }

    /**
     * Resolves and checks the current action method name
     *
     * @param ActionRequest $request
     * @return string Method name of the current action
     * @throws InvalidActionVisibilityException
     * @throws NoSuchActionException
     */
    protected function resolveActionMethodName(ActionRequest $request): string
    {
        $actionMethodName = $request->getControllerActionName() . 'Action';
        if (!is_callable([$this, $actionMethodName])) {
            throw new NoSuchActionException(sprintf('An action "%s" does not exist in controller "%s".', $actionMethodName, get_class($this)), 1186669086);
        }
        $publicActionMethods = static::getPublicActionMethods($this->objectManager);
        if (!isset($publicActionMethods[$actionMethodName])) {
            throw new InvalidActionVisibilityException(sprintf('The action "%s" in controller "%s" is not public!', $actionMethodName, get_class($this)), 1186669086);
        }
        return $actionMethodName;
    }

    /**
     * Implementation of the arguments initialization in the action controller:
     * Automatically registers arguments of the current action
     *
     * Don't override this method - use initializeAction() instead.
     *
     * @return void
     * @throws InvalidArgumentTypeException
     * @see initializeArguments()
     */
    protected function initializeActionMethodArguments(Arguments $arguments)
    {
        $actionMethodParameters = static::getActionMethodParameters($this->objectManager);
        if (isset($actionMethodParameters[$this->actionMethodName])) {
            $methodParameters = $actionMethodParameters[$this->actionMethodName];
        } else {
            $methodParameters = [];
        }

        $arguments->removeAll();
        foreach ($methodParameters as $parameterName => $parameterInfo) {
            $dataType = null;
            if (isset($parameterInfo['type'])) {
                $dataType = $parameterInfo['type'];
            } elseif ($parameterInfo['array']) {
                $dataType = 'array';
            }
            if ($dataType === null) {
                throw new InvalidArgumentTypeException('The argument type for parameter $' . $parameterName . ' of method ' . get_class($this) . '->' . $this->actionMethodName . '() could not be detected.', 1253175643);
            }
            $defaultValue = ($parameterInfo['defaultValue'] ?? null);
            if ($defaultValue === null && $parameterInfo['optional'] === true) {
                $dataType = TypeHandling::stripNullableType($dataType);
            }
            $mapRequestBody = isset($parameterInfo['mapRequestBody']) && $parameterInfo['mapRequestBody'] === true;
            $arguments->addNewArgument($parameterName, $dataType, ($parameterInfo['optional'] === false), $defaultValue, $mapRequestBody);
        }
    }

    /**
     * Returns a map of action method names and their parameters.
     *
     * @param ObjectManagerInterface $objectManager
     * @return array Array of method parameters by action name
     * @Flow\CompileStatic
     */
    public static function getActionMethodParameters($objectManager)
    {
        $reflectionService = $objectManager->get(ReflectionService::class);

        $result = [];

        $className = get_called_class();
        $methodNames = get_class_methods($className);
        foreach ($methodNames as $methodName) {
            if (strlen($methodName) > 6 && strpos($methodName, 'Action', strlen($methodName) - 6) !== false) {
                $result[$methodName] = $reflectionService->getMethodParameters($className, $methodName);

                /* @var $requestBodyAnnotation Flow\MapRequestBody */
                $requestBodyAnnotation = $reflectionService->getMethodAnnotation($className, $methodName, Flow\MapRequestBody::class);
                if ($requestBodyAnnotation !== null) {
                    $requestBodyArgument = $requestBodyAnnotation->argumentName;
                    if (!isset($result[$methodName][$requestBodyArgument])) {
                        throw new \Neos\Flow\Mvc\Exception('Can not map request body to non existing argument $' . $requestBodyArgument . ' of ' . $className . '->' . $methodName . '().', 1559236782);
                    }
                    $result[$methodName][$requestBodyArgument]['mapRequestBody'] = true;
                }
            }
        }

        return $result;
    }

    /**
     * This is a helper method purely used to make initializeActionMethodValidators()
     * testable without mocking static methods.
     *
     * @return array
     */
    protected function getInformationNeededForInitializeActionMethodValidators()
    {
        return [
            static::getActionValidationGroups($this->objectManager),
            static::getActionMethodParameters($this->objectManager),
            static::getActionValidateAnnotationData($this->objectManager),
            static::getActionIgnoredValidationArguments($this->objectManager)
        ];
    }

    /**
     * Adds the needed validators to the Arguments:
     *
     * - Validators checking the data type from the "@param" annotation
     * - Custom validators specified with validate annotations.
     * - Model-based validators (validate annotations in the model)
     * - Custom model validator classes
     *
     * @return void
     */
    protected function initializeActionMethodValidators(Arguments $arguments)
    {
        [$validateGroupAnnotations, $actionMethodParameters, $actionValidateAnnotations, $actionIgnoredArguments] = $this->getInformationNeededForInitializeActionMethodValidators();

        if (isset($validateGroupAnnotations[$this->actionMethodName])) {
            $validationGroups = $validateGroupAnnotations[$this->actionMethodName];
        } else {
            $validationGroups = ['Default', 'Controller'];
        }

        if (isset($actionMethodParameters[$this->actionMethodName])) {
            $methodParameters = $actionMethodParameters[$this->actionMethodName];
        } else {
            $methodParameters = [];
        }

        if (isset($actionValidateAnnotations[$this->actionMethodName])) {
            $validateAnnotations = $actionValidateAnnotations[$this->actionMethodName];
        } else {
            $validateAnnotations = [];
        }
        $parameterValidators = $this->validatorResolver->buildMethodArgumentsValidatorConjunctions(get_class($this), $this->actionMethodName, $methodParameters, $validateAnnotations);

        if (isset($actionIgnoredArguments[$this->actionMethodName])) {
            $ignoredArguments = $actionIgnoredArguments[$this->actionMethodName];
        } else {
            $ignoredArguments = [];
        }

        /* @var $argument Argument */
        foreach ($arguments as $argument) {
            $argumentName = $argument->getName();
            if (isset($ignoredArguments[$argumentName]) && !$ignoredArguments[$argumentName]['evaluate']) {
                continue;
            }

            $validator = $parameterValidators[$argumentName];

            $baseValidatorConjunction = $this->validatorResolver->getBaseValidatorConjunction($argument->getDataType(), $validationGroups);
            if (count($baseValidatorConjunction) > 0) {
                $validator->addValidator($baseValidatorConjunction);
            }
            $argument->setValidator($validator);
        }
    }

    /**
     * Returns a map of action method names and their validation groups.
     *
     * @param ObjectManagerInterface $objectManager
     * @return array Array of validation groups by action method name
     * @Flow\CompileStatic
     */
    public static function getActionValidationGroups($objectManager)
    {
        $reflectionService = $objectManager->get(ReflectionService::class);

        $result = [];

        $className = get_called_class();
        $methodNames = get_class_methods($className);
        foreach ($methodNames as $methodName) {
            if (strlen($methodName) > 6 && strpos($methodName, 'Action', strlen($methodName) - 6) !== false) {
                $validationGroupsAnnotation = $reflectionService->getMethodAnnotation($className, $methodName, Flow\ValidationGroups::class);
                if ($validationGroupsAnnotation !== null) {
                    $result[$methodName] = $validationGroupsAnnotation->validationGroups;
                }
            }
        }

        return $result;
    }

    /**
     * Returns a map of action method names and their validation parameters.
     *
     * @param ObjectManagerInterface $objectManager
     * @return array Array of validate annotation parameters by action method name
     * @Flow\CompileStatic
     */
    public static function getActionValidateAnnotationData($objectManager)
    {
        $reflectionService = $objectManager->get(ReflectionService::class);

        $result = [];

        $className = get_called_class();
        $methodNames = get_class_methods($className);
        foreach ($methodNames as $methodName) {
            if (strlen($methodName) > 6 && strpos($methodName, 'Action', strlen($methodName) - 6) !== false) {
                $validateAnnotations = $reflectionService->getMethodAnnotations($className, $methodName, Flow\Validate::class);
                $result[$methodName] = array_map(function ($validateAnnotation) {
                    return [
                        'type' => $validateAnnotation->type,
                        'options' => $validateAnnotation->options,
                        'argumentName' => $validateAnnotation->argumentName,
                    ];
                }, $validateAnnotations);
            }
        }

        return $result;
    }

    /**
     * Initializes the controller before invoking an action method.
     *
     * Override this method to solve tasks which all actions have in
     * common.
     *
     * @return void
     * @api
     */
    protected function initializeAction()
    {
    }

    /**
     * Calls the specified action method and passes the arguments.
     *
     * If the action returns a string, it is appended to the content in the
     * response object. If the action doesn't return anything and a valid
     * view exists, the view is rendered automatically.
     *
     * @param ActionRequest $request
     * @param Arguments $arguments
     * @param ActionResponse $response The most likely empty response, previously available as $this->response
     */
    protected function callActionMethod(ActionRequest $request, Arguments $arguments, ActionResponse $response): ResponseInterface
    {
        $preparedArguments = [];
        foreach ($arguments as $argument) {
            $preparedArguments[] = $argument->getValue();
        }

        $validationResult = $arguments->getValidationResults();

        if (!$validationResult->hasErrors()) {
            $actionResult = $this->{$this->actionMethodName}(...$preparedArguments);
        } else {
            $actionIgnoredArguments = static::getActionIgnoredValidationArguments($this->objectManager);
            if (isset($actionIgnoredArguments[$this->actionMethodName])) {
                $ignoredArguments = $actionIgnoredArguments[$this->actionMethodName];
            } else {
                $ignoredArguments = [];
            }

            // if there exists more errors than in ignoreValidationAnnotations => call error method
            // else => call action method
            $shouldCallActionMethod = true;
            /** @var Result $subValidationResult */
            foreach ($validationResult->getSubResults() as $argumentName => $subValidationResult) {
                if (!$subValidationResult->hasErrors()) {
                    continue;
                }
                if (isset($ignoredArguments[$argumentName]) && $subValidationResult->getErrors(TargetNotFoundError::class) === []) {
                    continue;
                }
                $shouldCallActionMethod = false;
                break;
            }

            if ($shouldCallActionMethod) {
                $actionResult = $this->{$this->actionMethodName}(...$preparedArguments);
            } else {
                $actionResult = $this->{$this->errorMethodName}();
            }
        }

        // freeze $response previously available as $this->response
        $httpResponse = $response->buildHttpResponse();

        if ($actionResult instanceof ResponseInterface) {
            return $actionResult;
        }

        if ($actionResult === null && $this->view instanceof ViewInterface) {
            $result = $this->view->render();

            if ($result instanceof Response) {
                // merging of the $httpResponse (previously $this->response) was previously done to a limited extend via the use of replaceHttpResponse.
                // With Flow 9 the returned response will overrule any changes made to $this->response as there is no clear way to merge them.
                return $result;
            }

            return $httpResponse->withBody($result);
        }

        return $httpResponse->withBody(Utils::streamFor($actionResult));
    }

    /**
     * @param ObjectManagerInterface $objectManager
     * @return array Array of argument names as key by action method name
     * @Flow\CompileStatic
     */
    public static function getActionIgnoredValidationArguments($objectManager)
    {
        $reflectionService = $objectManager->get(ReflectionService::class);

        $result = [];

        $className = get_called_class();
        $methodNames = get_class_methods($className);
        foreach ($methodNames as $methodName) {
            if (strlen($methodName) > 6 && strpos($methodName, 'Action', strlen($methodName) - 6) !== false) {
                $ignoreValidationAnnotations = $reflectionService->getMethodAnnotations($className, $methodName, Flow\IgnoreValidation::class);
                /** @var Flow\IgnoreValidation $ignoreValidationAnnotation */
                foreach ($ignoreValidationAnnotations as $ignoreValidationAnnotation) {
                    if (!isset($ignoreValidationAnnotation->argumentName)) {
                        throw new \InvalidArgumentException('An IgnoreValidation annotation on a method must be given an argument name.', 1318456607);
                    }
                    $result[$methodName][$ignoreValidationAnnotation->argumentName] = [
                        'evaluate' => $ignoreValidationAnnotation->evaluate
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @param ObjectManagerInterface $objectManager
     * @return array Array of all public action method names, indexed by method name
     * @Flow\CompileStatic
     */
    public static function getPublicActionMethods($objectManager)
    {
        /** @var ReflectionService $reflectionService */
        $reflectionService = $objectManager->get(ReflectionService::class);

        $result = [];

        $className = get_called_class();
        $methodNames = get_class_methods($className);
        foreach ($methodNames as $methodName) {
            if (strlen($methodName) > 6 && strpos($methodName, 'Action', strlen($methodName) - 6) !== false) {
                if ($reflectionService->isMethodPublic($className, $methodName)) {
                    $result[$methodName] = true;
                }
            }
        }

        return $result;
    }

    /**
     * Prepares a view for the current action and stores it in $this->view.
     * By default, this method tries to locate a view with a name matching
     * the current action.
     *
     * @return ViewInterface the resolved view
     * @throws ViewNotFoundException if no view can be resolved
     */
    protected function resolveView(ActionRequest $request)
    {
        $viewsConfiguration = $this->viewConfigurationManager->getViewConfiguration($request);
        $viewObjectName = $this->defaultViewImplementation;
        if (!empty($this->defaultViewObjectName)) {
            $viewObjectName = $this->defaultViewObjectName;
        }
        $viewObjectName = $this->resolveViewObjectName($request) ?: $viewObjectName;
        if (isset($viewsConfiguration['viewObjectName'])) {
            $viewObjectName = $viewsConfiguration['viewObjectName'];
        }

        if (!is_a($viewObjectName, ViewInterface::class, true)) {
            throw new ViewNotFoundException(sprintf(
                'View class has to implement ViewInterface but "%s" in action "%s" of controller "%s" does not.',
                $viewObjectName,
                $request->getControllerActionName(),
                get_class($this)
            ), 1355153188);
        }

        $viewOptions = $viewsConfiguration['options'] ?? [];
        $view = $viewObjectName::createWithOptions($viewOptions);

        $this->emitViewResolved($view);

        return $view;
    }

    /**
     * Emit that the view is resolved. The passed ViewInterface reference,
     * gives the possibility to add variables to the view,
     * before passing it on to further rendering
     *
     * @param ViewInterface $view
     * @Flow\Signal
     */
    protected function emitViewResolved(ViewInterface $view)
    {
    }

    /**
     * Determines the fully qualified view object name.
     *
     * @return mixed The fully qualified view object name or false if no matching view could be found.
     * @api
     */
    protected function resolveViewObjectName(ActionRequest $request)
    {
        $possibleViewObjectName = $this->viewObjectNamePattern;
        $packageKey = $request->getControllerPackageKey();
        $subpackageKey = $request->getControllerSubpackageKey();
        $format = $request->getFormat();

        if ($subpackageKey !== null && $subpackageKey !== '') {
            $packageKey .= '\\' . $subpackageKey;
        }
        $possibleViewObjectName = str_replace('@package', str_replace('.', '\\', $packageKey), $possibleViewObjectName);
        $possibleViewObjectName = str_replace('@controller', $request->getControllerName(), $possibleViewObjectName);
        $possibleViewObjectName = str_replace('@action', $request->getControllerActionName(), $possibleViewObjectName);

        $viewObjectName = $this->objectManager->getCaseSensitiveObjectName(strtolower(str_replace('@format', $format, $possibleViewObjectName)));
        if ($viewObjectName === null) {
            $viewObjectName = $this->objectManager->getCaseSensitiveObjectName(strtolower(str_replace('@format', '', $possibleViewObjectName)));
        }
        if ($viewObjectName === null && isset($this->viewFormatToObjectNameMap[$format])) {
            $viewObjectName = $this->viewFormatToObjectNameMap[$format];
        }
        return $viewObjectName;
    }

    /**
     * Initializes the view before invoking an action method.
     *
     * Override this method to solve assign variables common for all actions
     * or prepare the view in another way before the action is called.
     *
     * @param ViewInterface $view The view to be initialized
     * @return void
     * @api
     */
    protected function initializeView(ViewInterface $view)
    {
    }

    /**
     * A special action which is called if the originally intended action could
     * not be called, for example if the arguments were not valid.
     *
     * The default implementation checks for TargetNotFoundErrors, sets a flash message, request errors and forwards back
     * to the originating action. This is suitable for most actions dealing with form input.
     *
     * @return string
     * @api
     */
    protected function errorAction()
    {
        $this->handleTargetNotFoundError();
        $this->addErrorFlashMessage();
        $this->forwardToReferringRequest();

        return $this->getFlattenedValidationErrorMessage();
    }

    /**
     * Checks if the arguments validation result contain errors of type TargetNotFoundError and throws a TargetNotFoundException if that's the case for a top-level object.
     * You can override this method (or the errorAction()) if you need a different behavior
     *
     * @return void
     * @throws TargetNotFoundException
     * @api
     */
    protected function handleTargetNotFoundError()
    {
        foreach (array_keys($this->request->getArguments()) as $argumentName) {
            /** @var TargetNotFoundError $targetNotFoundError */
            $targetNotFoundError = $this->arguments->getValidationResults()->forProperty($argumentName)->getFirstError(TargetNotFoundError::class);
            if ($targetNotFoundError !== false) {
                throw new TargetNotFoundException($targetNotFoundError->getMessage(), $targetNotFoundError->getCode());
            }
        }
    }

    /**
     * If an error occurred during this request, this adds a flash message describing the error to the flash
     * message container.
     *
     * @return void
     */
    protected function addErrorFlashMessage()
    {
        $errorFlashMessage = $this->getErrorFlashMessage();
        if ($errorFlashMessage !== false) {
            $this->controllerContext->getFlashMessageContainer()->addMessage($errorFlashMessage);
        }
    }

    /**
     * If information on the request before the current request was sent, this method forwards back
     * to the originating request. This effectively ends processing of the current request, so do not
     * call this method before you have finished the necessary business logic!
     *
     * @return void
     * @throws ForwardException
     */
    protected function forwardToReferringRequest()
    {
        $referringRequest = $this->request->getReferringRequest();
        if ($referringRequest === null) {
            return;
        }
        $packageKey = $referringRequest->getControllerPackageKey();
        $subpackageKey = $referringRequest->getControllerSubpackageKey();
        if ($subpackageKey !== null) {
            $packageKey .= '\\' . $subpackageKey;
        }
        $argumentsForNextController = $referringRequest->getArguments();
        $argumentsForNextController['__submittedArguments'] = $this->request->getArguments();
        $argumentsForNextController['__submittedArgumentValidationResults'] = $this->arguments->getValidationResults();

        $this->forward($referringRequest->getControllerActionName(), $referringRequest->getControllerName(), $packageKey, $argumentsForNextController);
    }

    /**
     * Returns a string containing all validation errors separated by PHP_EOL.
     *
     * @return string
     */
    protected function getFlattenedValidationErrorMessage()
    {
        $outputMessage = 'Validation failed while trying to call ' . get_class($this) . '->' . $this->actionMethodName . '().' . PHP_EOL;
        $logMessage = $outputMessage;

        foreach ($this->arguments->getValidationResults()->getFlattenedErrors() as $propertyPath => $errors) {
            foreach ($errors as $error) {
                $logMessage .= 'Error for ' . $propertyPath . ':  ' . $error->render() . PHP_EOL;
            }
        }
        $this->logger->error($logMessage, LogEnvironment::fromMethodName(__METHOD__));

        return $outputMessage;
    }

    /**
     * A template method for displaying custom error flash messages, or to
     * display no flash message at all on errors. Override this to customize
     * the flash message in your action controller.
     *
     * @return Error\Error|false The flash message or false if no flash message should be set
     * @api
     */
    protected function getErrorFlashMessage()
    {
        return new Error\Error('An error occurred while trying to call %1$s->%2$s()', null, [get_class($this), $this->actionMethodName]);
    }
}
