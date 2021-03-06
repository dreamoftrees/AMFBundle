<?php

namespace Tecbot\AMFBundle\Amf\Service;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Tecbot\AMFBundle\Amf\BodyRequest;

/**
 * ServiceResolver.
 *
 * @author Thomas Adam <thomas.adam@tebot.de>
 */
class ServiceResolver implements ServiceResolverInterface
{
    protected $container;
    protected $parser;
    protected $services;
    protected $logger;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container A ContainerInterface instance
     * @param ServiceNameParser  $parser    A ServiceNameparser instance
     * @param array              $services  An array of mapped services
     * @param LoggerInterface    $logger    A LoggerInterface instance
     */
    public function __construct(ContainerInterface $container, ServiceNameParser $parser, array $services = array(), LoggerInterface $logger = null)
    {
        $this->container = $container;
        $this->parser = $parser;

        foreach ($services as $alias => $service) {
            $this->services[strtolower($alias)] = $service['class'];
        }

        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function getService(BodyRequest $bodyRequest)
    {
        $alias = strtolower($bodyRequest->getSource());
        if (!isset($this->services[$alias])) {
            if (null !== $this->logger) {
                $this->logger->warn(sprintf('Unable to find mapping for Amf service %s.', $alias));
            }

            return false;
        }

        $serviceClass = $this->createService($this->services[$alias]);
        $method = $bodyRequest->getMethod() . 'Action'; // add method suffix

        if (!method_exists($serviceClass, $method)) {
            throw new \InvalidArgumentException(sprintf('Method "%s::%s" does not exist.', get_class($serviceClass), $method));
        }

        return array($serviceClass, $method);
    }

    /**
     * {@inheritdoc}
     */
    public function getArguments(BodyRequest $bodyRequest, array $service)
    {
        $arguments = $bodyRequest->getArguments();
        if (null === $arguments) {
            $arguments = array();
        }

        list($serviceClass, $method) = $service;
        $r = new \ReflectionMethod($serviceClass, $method);
        $parameters = $r->getParameters();

        if (0 < count($parameters)) {
            $arguments = array_merge($arguments, $parameters);
        }

        return $arguments;
    }

    /**
     * Returns a callable for the given amf service.
     *
     * @param  string $service A Amf Service string
     *
     * @return mixed  A PHP callable
     */
    protected function createService($service)
    {
        $count = substr_count($service, ':');
        if (1 == $count) {
            // Amf service in the a:b notation then
            $service = $this->parser->parse($service);
        } else if (0 == $count) {
            // Amf service in the service notation
            return $this->container->get($service);
        } else {
            throw new \LogicException(sprintf('Unable to parse the Amf service name "%s".', $service));
        }

        if (!class_exists($service)) {
            throw new \InvalidArgumentException(sprintf('Class "%s" does not exist.', $service));
        }

        $serviceClass = new $service();
        if ($serviceClass instanceof ContainerAwareInterface) {
            $serviceClass->setContainer($this->container);
        }

        return $serviceClass;
    }
}