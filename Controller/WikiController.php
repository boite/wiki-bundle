<?php

namespace LinkORB\Bundle\WikiBundle\Controller;

use LinkORB\Bundle\WikiBundle\Entity\Wiki;
use LinkORB\Bundle\WikiBundle\Form\WikiSearchType;
use LinkORB\Bundle\WikiBundle\Form\WikiType;
use LinkORB\Bundle\WikiBundle\Services\WikiEventService;
use LinkORB\Bundle\WikiBundle\Services\WikiPageService;
use LinkORB\Bundle\WikiBundle\Services\WikiService;
use RuntimeException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @Route("/wiki")
 */
class WikiController extends AbstractController
{
    private $wikiService;

    public function __construct(WikiService $wikiService)
    {
        $this->wikiService = $wikiService;
    }

    /**
     * @Route("", name="wiki_index", methods="GET")
     */
    public function indexAction(): Response
    {
        $wikis = $this->wikiService->getAllWikis();

        $wikiArray = [];
        foreach ($wikis as $wiki) {
            if ($wikiRoles = $this->wikiService->getWikiPermission($wiki)) {
                if ($wikiRoles['readRole']) {
                    $wikiArray[] = $wiki;
                }
            }
        }

        usort($wikiArray, function ($a, $b) {
            return strcmp($a->getName(), $b->getName());
        });

        return $this->render(
            '@Wiki/wiki/index.html.twig',
            ['wikis' => $wikiArray]
        );
    }

    /**
     * @Route("/add", name="wiki_add", methods="GET|POST")
     */
    public function addAction(Request $request, WikiEventService $wikiEventService): Response
    {
        $wiki = new Wiki();
        if ($request->get('wikiname')) {
            $wiki->setName($request->get('wikiname'));
        }

        return $this->getEditForm($request, $wiki, $wikiEventService);
    }

    /**
     * @Route("/search", name="wiki_search", methods="GET|POST")
     */
    public function serchAction(Request $request): Response
    {
        $wikiArray = [];
        $wikiIds = [];
        $wikiPages = [];

        foreach ($this->wikiService->getAllWikis() as $wiki) {
            if ($wikiRoles = $this->wikiService->getWikiPermission($wiki)) {
                if ($wikiRoles['readRole']) {
                    $wikiArray[$wiki->getName()] = $wiki->getName();
                    $wikiIds[] = $wiki->getId();
                }
            }
        }

        asort($wikiArray);
        $form = $this->createForm(WikiSearchType::class, ['wikiName' => $request->get('wikiName')], ['method' => 'GET', 'csrf_protection' => false, 'wikiArray' => $wikiArray]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $formData = $form->getData();

            if (!empty($formData['wikiName'])) {
                if (!$wiki = $this->wikiService->getWikiByName($formData['wikiName'])) {
                    throw new RuntimeException('Wiki '.$formData['wikiName'].'not found', Response::HTTP_NOT_FOUND);
                }
                if (!$wikiRoles = $this->wikiService->getWikiPermission($wiki)) {
                    throw new AccessDeniedException('Access denied!');
                }
                $wikiIds = [$wiki->getId()];
            }

            $wikiPageResults = [];
            if (!empty($formData['search'])) {
                $wikiPageResults = $this->wikiService->searchWiki($formData['search'], $wikiIds);
            }

            foreach ($wikiPageResults as $wikiPageResult) {
                $tmpVar = $wikiPageResult[0];
                $tmpVar->setPoints($wikiPageResult['points']);
                $wikiPages[] = $tmpVar;
            }
        }

        return $this->render('@Wiki/wiki/search.html.twig', [
            'form' => $form->createView(),
            'wikiPages' => $wikiPages,
        ]);
    }

    /**
     * @Route("/{wikiName}/edit", name="wiki_edit", methods="GET|POST")
     * @ParamConverter("wiki", options={"mapping"={"wikiName"="name"}})
     */
    public function editAction(Request $request, Wiki $wiki, WikiEventService $wikiEventService): Response
    {
        if (!$wikiRoles = $this->wikiService->getWikiPermission($wiki)) {
            throw new AccessDeniedException('Access denied!');
        }
        if (!$wikiRoles['writeRole']) {
            throw new AccessDeniedException('Access denied!');
        }

        return $this->getEditForm($request, $wiki, $wikiEventService);
    }

    /**
     * @Route("/{wikiName}/delete", name="wiki_delete", methods="GET")
     * @ParamConverter("wiki", options={"mapping"={"wikiName"="name"}})
     */
    public function deleteAction(Request $request, Wiki $wiki, WikiEventService $wikiEventService): Response
    {
        if (!$wikiRoles = $this->wikiService->getWikiPermission($wiki)) {
            throw new AccessDeniedException('Access denied!');
        }
        if (!$wikiRoles['writeRole']) {
            throw new AccessDeniedException('Access denied!');
        }

        if (count($wiki->getWikiPages())) {
            $this->addFlash('error', 'The wiki cannot be deleted because of having a wiki-page.');
        } else {
            $wikiEventService
                ->createEvent(
                    'wiki.deleted',
                    $wiki->getId(),
                    json_encode([
                        'deletedAt' => time(),
                        'deletedBy' => $this->getUser()->getUsername(),
                        'name' => $wiki->getName(),
                    ])
                );
            $em = $this->getDoctrine()->getManager();
            $em->remove($wiki);
            $em->flush();
        }

        return $this->redirectToRoute('wiki_index');
    }

    protected function getEditForm(Request $request, Wiki $wiki, WikiEventService $wikiEventService)
    {
        $form = $this->createForm(WikiType::class, $wiki);
        $form->handleRequest($request);

        $add = !$wiki->getid();

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($wiki);
            $em->flush();

            if ($add) {
                $wikiEventService->createEvent(
                    'wiki.created',
                    $wiki->getId(),
                    json_encode([
                        'createdAt' => time(),
                        'createdBy' => $this->getUser()->getUsername(),
                        'name' => $wiki->getName(),
                        'description' => $wiki->getDescription(),
                    ])
                );
            } else {
                $wikiEventService->createEvent(
                    'wiki.updated',
                    $wiki->getId(),
                    json_encode([
                        'updatedAt' => time(),
                        'updatedBy' => $this->getUser()->getUsername(),
                        'name' => $wiki->getName(),
                        'description' => $wiki->getDescription(),
                    ])
                );
            }

            return $this->redirectToRoute('wiki_view', ['wikiName' => $wiki->getName()]);
        }

        return $this->render('@Wiki/wiki/edit.html.twig', [
            'wiki' => $wiki,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{wikiName}", name="wiki_view", methods="GET")
     * ParamConverter("wiki", options={"mapping"={"wikiName"="name"}})
     */
    public function viewAction(WikiPageService $wikiPageService, $wikiName): Response
    {
        if (!$wiki = $this->wikiService->getWikiByName($wikiName)) {
            return $this->render(
                '@Wiki/wiki/new.html.twig',
                ['wikiName' => $wikiName]
            );
        }
        if (!$wikiRoles = $this->wikiService->getWikiPermission($wiki)) {
            throw new AccessDeniedException('Access denied!');
        }
        $data = $wikiRoles;
        // $wikiPageRepository = $this->get('LinkORB\Bundle\WikiBundle\Repository\WikiPageRepository');

        $indexPage = $wikiPageService->getOneByWikiIdAndPageName($wiki->getId(), 'index');
        if ($indexPage) {
            return $this->redirectToRoute('wiki_page_view', ['wikiName' => $wiki->getName(), 'pageName' => $indexPage->getName()]);
        }

        $wikiPages = $wikiPageService->getByWikiIdAndParentId($wiki->getId());

        foreach ($wikiPages as $wikiPage) {
            $wikiPage->setChildPages($wikiPageService->recursiveChild($wikiPage));
        }

        $data['wikiPages'] = $wikiPages;
        $data['wiki'] = $wiki;

        return $this->render('@Wiki/wiki_page/index.html.twig', $data);
    }

    /**
     * @Route("/{wikiName}/export", name="wiki_export", methods="GET")
     * @ParamConverter("wiki", options={"mapping"={"wikiName"="name"}})
     */
    public function exportAction(Wiki $wiki, WikiService $wikiService): Response
    {
        if (!$wikiRoles = $this->wikiService->getWikiPermission($wiki)) {
            throw new AccessDeniedException('Access denied!');
        }

        $json = json_encode($wikiService->export($wiki), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $filename = $wiki->getName().'.json';
        $response = new Response($json);
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
}
