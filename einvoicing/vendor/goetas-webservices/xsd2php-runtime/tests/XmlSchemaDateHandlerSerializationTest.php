<?php
namespace GoetasWebservices\Xsd\XsdToPhpRuntime\Tests\Jms\Handler;

use Doctrine\Common\Annotations\AnnotationReader;
use GoetasWebservices\Xsd\XsdToPhpRuntime\Jms\Handler\XmlSchemaDateHandler;
use JMS\Serializer\Accessor\DefaultAccessorStrategy;
use JMS\Serializer\Construction\UnserializeObjectConstructor;
use JMS\Serializer\EventDispatcher\EventDispatcher;
use JMS\Serializer\GraphNavigator;
use JMS\Serializer\Handler\HandlerRegistry;
use JMS\Serializer\Metadata\Driver\AnnotationDriver;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\XmlSerializationVisitor;
use Metadata\MetadataFactory;
use PHPUnit\Framework\TestCase;

class XmlSchemaDateHandlerSerializationTest extends TestCase
{
    /**
     * @var XmlSchemaDateHandler
     */
    protected $handler;
    /**
     * @var SerializationContext
     */
    protected $context;

    /**
     * @var XmlSerializationVisitor
     */
    protected $visitor;

    public function setUp(): void
    {
        $this->handler = new XmlSchemaDateHandler();
        $this->context = SerializationContext::create();

        $naming = new IdenticalPropertyNamingStrategy();
        $cons = new UnserializeObjectConstructor();

        $dispatcher = new EventDispatcher();
        $handlerRegistry= new HandlerRegistry();

        $navigator = class_exists('JMS\Serializer\GraphNavigator\DeserializationGraphNavigator')
            ? $this->initJmsv2($naming, $handlerRegistry, $cons, $dispatcher)
            : $this->initJmsv1($naming, $handlerRegistry, $cons, $dispatcher)
        ;
        $this->visitor->setNavigator($navigator);
    }

    private function initJmsv2($naming, $handlerRegistry, $cons, $dispatcher)
    {
        $accessor = new DefaultAccessorStrategy();
        $this->visitor = new XmlSerializationVisitor();
        $metadataFactory = new MetadataFactory(new AnnotationDriver(new AnnotationReader(), $naming));
        return new GraphNavigator\SerializationGraphNavigator($metadataFactory, $handlerRegistry, $accessor, $dispatcher);
    }

    private function initJmsv1($naming, $handlerRegistry, $cons, $dispatcher)
    {
        $this->visitor = new XmlSerializationVisitor($naming);
        $metadataFactory = new MetadataFactory(new AnnotationDriver(new AnnotationReader()));
        return new GraphNavigator($metadataFactory, $handlerRegistry, $cons, $dispatcher);
    }

    /**
     * @dataProvider getSerializeDateTime
     * @param \DateTime $date
     */
    public function testSerializeDateTime(\DateTime $date, $expected)
    {
        $ret = $this->handler->serializeDateTime($this->visitor, $date, [], $this->context);
        $actual = $ret ? $ret->nodeValue : $this->visitor->getCurrentNode()->nodeValue;
        $this->assertEquals($expected, $actual);
    }

    public function getSerializeDateTime()
    {
        return [
            [new \DateTime('2015-01-01 12:00+00:00'), '2015-01-01T12:00:00+00:00'],
            [new \DateTime('2015-01-01 12:00:56+00:00'), '2015-01-01T12:00:56+00:00'],
            [new \DateTime('2015-01-01 12:00:56+00:00'), '2015-01-01T12:00:56+00:00'],
            [new \DateTime('2015-01-01 12:00:56+20:00'), '2015-01-01T12:00:56+20:00'],
            [new \DateTime('2015-01-01 12:00:56', new \DateTimeZone("Europe/London")), '2015-01-01T12:00:56+00:00'],
            [new \DateTime('2015-01-01 12:00:56+00:00', new \DateTimeZone("Europe/London")), '2015-01-01T12:00:56+00:00'],
            [new \DateTime('2015-01-01 12:00:56', new \DateTimeZone("Europe/Rome")), '2015-01-01T12:00:56+01:00'],
        ];
    }

    /**
     * @dataProvider getSerializeDate
     * @param \DateTime $date
     * @param string    $expected
     */
    public function testSerializeDate(\DateTime $date, $expected)
    {
        $ret = $this->handler->serializeDate($this->visitor, $date, [], $this->context);

        $actual = $ret ? $ret->nodeValue : $this->visitor->getCurrentNode()->nodeValue;
        $this->assertEquals($expected, $actual);
    }

    public function getSerializeDate()
    {
        return [
            [new \DateTime('2015-01-01 12:00'), '2015-01-01'],
            [new \DateTime('2015-01-01 12:00:56'), '2015-01-01'],
            [new \DateTime('2015-01-01 12:00:56+00:00'), '2015-01-01'],
            [new \DateTime('2015-01-01 12:00:56+20:00'), '2015-01-01'],
            [new \DateTime('2015-01-01 12:00:56', new \DateTimeZone("Europe/London")), '2015-01-01'],
        ];
    }
}
