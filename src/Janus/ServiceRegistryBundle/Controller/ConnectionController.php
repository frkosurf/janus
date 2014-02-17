<?php
/**
 * @author Lucas van Lierop <lucas@vanlierop.org>
 */

namespace Janus\ServiceRegistryBundle\Controller;

use Janus\ServiceRegistry\Service\ConnectionService;
use Janus\ServiceRegistryBundle\DependencyInjection\AuthenticationProvider;
use Janus\ServiceRegistryBundle\Form\Type\ConnectionType;
use Janus\ServiceRegistry\Entity\Connection\Revision;
use Janus\ServiceRegistry\Connection\Dto;
use Janus\ServiceRegistryBundle\Model\ConnectionCollection;

use FOS\RestBundle\Util\Codes;

use FOS\RestBundle\Controller\Annotations;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Request\ParamFetcherInterface;
use FOS\RestBundle\View\RouteRedirectView;
use FOS\RestBundle\View\View;

use Monolog\Logger;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

use SimpleSAML_Configuration;

/**
 * Rest controller for connections
 *
 * @package Janus\ServiceRegistryBundle\Controller
 */
class ConnectionController extends FOSRestController
{
    /**
     * List all connections.
     *
     * @ApiDoc(
     *   resource = true,
     *   statusCodes = {
     *     200 = "Returned when successful"
     *   }
     * )
     *
     * @Annotations\View()
     *
     * @param Request               $request      the request object
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     *
     * @return array
     */
    public function getConnectionsAction(Request $request, ParamFetcherInterface $paramFetcher)
    {
        $this->log("Trying to get connections");

        $connectionRevisions = $this->get('connection_service')->load();
        $connections = array();
        /** @var $connectionRevision Revision */
        foreach ($connectionRevisions as $connectionRevision) {
            $connection = $connectionRevision->toDto();
            // @todo improve this with a view?
            // Manipulation code does not have to be in output
            $connection->setManipulationCode(null);
            $connection->setArpAttributes(null);
            $connections[$connection->getType()][$connection->getId()] = $connection;
        }

        $collection = new ConnectionCollection($connections);

        $this->log("Returning connections");

        return $collection;
    }

    /**
     * @param int $id
     * @return Revision
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    private function getLatestRevision($id)
    {
        /** @var ConnectionService $connectionService */
        $connectionService = $this->get('connection_service');
        $connectionRevision = $connectionService->getLatestRevision($id);
        if (!$connectionRevision instanceof Revision) {
            $this->log("Connection '{$id}' was not found");
            throw $this->createNotFoundException("Connection does not exist.");
        }

        return $connectionRevision;
    }

    /**
     * Get a single connection.
     *
     * @ApiDoc(
     *   resource = true,
     *   output = "\Janus\ServiceRegistry\Connection\Dto",
     *   statusCodes = {
     *     200 = "Returned when successful",
     *     404 = "Returned when the connection is not found"
     *   }
     * )
     *
     * @Annotations\View(templateVar="connection")
     *
     * @param int     $id      the connection id
     *
     * @return View
     *
     * @throws NotFoundHttpException when connection not exist
     */
    public function getConnectionAction($id)
    {
        $this->log("Trying to get connection '{$id}'");

        $connection = $this->getLatestRevision($id);
        $connections[$id] = $connection->toDto();
        $view = new View($connections[$id]);

        $this->log("Returned connection '{$id}'");

        return $view;
    }

    /**
     * Presents the form to use to create a new connection.
     *
     * @ApiDoc(
     *   resource = true,
     *   statusCodes = {
     *     200 = "Returned when successful"
     *   }
     * )
     *
     * @Annotations\View()
     *
     * @return FormTypeInterface
     */
    public function newConnectionAction()
    {
        $this->log("Trying to show edit form for new connection");

        $dto = $this->createDefaultDto();

        /** @var SimpleSAML_Configuration $janusConfig */
        $janusConfig = $this->get('janus_config');

        $form = $this->createForm(new ConnectionType($janusConfig), $dto);

        $this->log("Showing create form for new connection");

        return $form;
    }

    /**
     * @return Dto
     */
    private function createDefaultDto()
    {
        $dto = new Dto();
        $dto->setState('testaccepted');
        $dto->setIsActive(true);
        $dto->setAllowAllEntities(true);

        return $dto;
    }

    /**
     * Creates a new connection from the submitted data.
     *
     * @ApiDoc(
     *   resource = true,
     *   input = "Janus\ServiceRegistryBundle\Form\Type\ConnectionType",
     *   statusCodes = {
     *     201 = "Returned when created",
     *     400 = "Returned when the form has errors"
     *   }
     * )
     *
     * @Annotations\View(
     *   template = "JanusServiceRegistryBundle:Connection:newConnection.html.twig",
     *   statusCode = Codes::HTTP_BAD_REQUEST
     * )
     *
     * @param Request $request the request object
     *
     * @return FormTypeInterface|RouteRedirectView
     */
    public function postConnectionAction(Request $request)
    {
        $this->log("Trying to create connection via POST");

        $connectionDto = $this->createDefaultDto();

        return $this->createRevision($connectionDto, $request);
    }

    /**
     * Presents the form to use to update an existing connection.
     *
     * @ApiDoc(
     *   resource = true,
     *   statusCodes={
     *     200="Returned when successful",
     *     404={
     *       "Returned when the connection is not found",
     *     }
     *   }
     * )
     *
     * @Annotations\View()
     *
     * @param Request $request the request object
     * @param int     $id      the connection id
     *
     * @return FormTypeInterface
     *
     * @throws NotFoundHttpException when connection not exist
     */
    public function editConnectionAction(Request $request, $id)
    {
        $this->log("Trying to show edit form for Connection '{$id}'");

        $connections[$id] = $this->getLatestRevision($id);
        /** @var SimpleSAML_Configuration $janusConfig */
        $janusConfig = $this->get('janus_config');
        $form = $this->createForm(new ConnectionType($janusConfig), $connections[$id]->toDto());

        $this->log("Showing edit form for Connection '{$id}'");

        return $form;
    }

    /**
     * Update existing connection from the submitted data or create a new connection at a specific location.
     *
     * @ApiDoc(
     *   resource = true,
     *   input = "Janus\ServiceRegistryBundle\Form\Type\ConnectionType",
     *   statusCodes = {
     *     200 = "Returned when successful",
     *     400 = "Returned when the form has errors",
     *   }
     * )
     *
     * @Annotations\View(
     *   template="JanusServiceRegistryBundle:Connection:editConnection.html.twig"
     * )
     *
     * @param Request $request
     * @param int $id
     *
     * @return FormTypeInterface|RouteRedirectView
     *
     * @throws NotFoundHttpException when connection not exist
     */
    public function putConnectionAction(Request $request, $id)
    {
        $this->log("Trying to update connection '{$id} via PUT'");

        $connectionRevision = $this->getLatestRevision($id);
        $connectionDto = $connectionRevision->toDto();

        return $this->createRevision($connectionDto, $request);
    }

    /**
     * @param Dto $connectionDto
     * @param Request $request
     * @return array|View
     */
    private function createRevision(Dto $connectionDto, Request $request)
    {
        /** @var SimpleSAML_Configuration $janusConfig */
        $janusConfig = $this->get('janus_config');

        $form = $this->createForm(new ConnectionType($janusConfig), $connectionDto);
        $form->submit($request);
        if ($form->isValid()) {
            // @todo fix secret checking?
            /*
            if (!isset($connection->secret)) {
                $connection->secret = base64_encode($this->get('security.secure_random')->nextBytes(64));
            }
            */

            try {
                /** @var ConnectionService $connectionService */
                $connectionService = $this->get('connection_service');
                $connection = $connectionService->createFromDto($connectionDto);

                if ($connection->getRevisionNr() == 0) {
                    $this->log("Connection '{$connection->getId()}' created");
                    $statusCode = Codes::HTTP_CREATED;
                } else {
                    $this->log("Connection '{$connection->getId()}' updated to revision '{$connection->getRevisionNr()}'");
                    $statusCode = Codes::HTTP_OK;
                }

                return $this->routeRedirectView('get_connections', array(), $statusCode);
            } // @todo Improve this with proper validation
            catch (\InvalidArgumentException $ex) {
                $this->log("Creating revision failed, due to invalid data which was not catched by validation'");
                throw new BadRequestHttpException($ex->getMessage());
            } catch (\Exception $ex) {
                $this->log("Creating revision failed, due to exception'");
                throw $ex;
            }
        }

        $this->log("Creating revision failed due to invalid data");

        return array(
            'form' => $form
        );
    }

    /**
     * Removes a connection.
     *
     * @ApiDoc(
     *   resource = true,
     *   statusCodes={
     *     204="Returned when successful",
     *   }
     * )
     *
     * @param Request $request the request object
     * @param int     $id      the connection id
     *
     * @return RouteRedirectView
     *
     * @throws NotFoundHttpException when connection not exist
     */
    public function deleteConnectionAction(Request $request, $id)
    {
        $this->log("Trying to delete connection '{$id}'");

        /** @var ConnectionService $connectionService */
        $connectionService = $this->get('connection_service');
        $connectionService->deleteById($id);

        $this->log("Deleted connection '{$id}'");

        return $this->routeRedirectView('get_connections', array(), Codes::HTTP_NO_CONTENT);
    }

    /**
     * Removes a connection.
     *
     * @ApiDoc(
     *   resource = true,
     *   statusCodes={
     *     204="Returned when successful",
     *     404={
     *       "Returned when the connection is not found",
     *     }
     *   }
     * )
     *
     * @param Request $request the request object
     * @param int     $id      the connection id
     *
     * @return RouteRedirectView
     */
    public function removeConnectionAction(Request $request, $id)
    {
        $this->log("Trying to remove connection '{$id}'");

        return $this->deleteConnectionAction($request, $id);
    }

    /**
     * Logs message including usename suffix
     *
     * @param string $message
     */
    private function log($message)
    {
        // Logging
        /** @var Logger $logger */
        $logger = $this->get('logger');

        /** @var AuthenticationProvider $authenticationProvider */
        $authenticationProvider = $this->get('authentication_provider');
        $username = $authenticationProvider->getLoggedInUsername();

        $logger->addInfo($message . " (User: '{$username}')");
    }
}
