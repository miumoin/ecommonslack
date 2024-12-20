<?php
namespace App\Controller;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class HomeController extends AbstractController
{
    private $params;
    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }
    #[Route('/{reactRouting}', name: 'home', requirements: ['reactRouting' => '^(?!api|webhooks).*'], defaults: ['reactRouting' => null])]
    public function index( Request $request ): Response
    {
        $shop = ( isset( $_REQUEST['code'] ) ? null : ( isset( $_REQUEST['shop'] ) ? $_REQUEST['shop'] : null ) );
        $code = $_REQUEST['code'] ?? null;
        $response = $this->render('index.html.twig', ['shopify_api_key' => $this->params->get('shopify.api_key'), 'shop' => $shop]);

        return $response;
    }
}