<?php
/**
 * @package Newscoop
 * @subpackage Subscriptions
 * @copyright 2011 Sourcefabric o.p.s.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

/**
 * PlaylistController
 * @Acl(resource="playlist", action="manage")
 */
use Newscoop\Utils\Exception;
use Newscoop\Service\Implementation\var_hook;
use Newscoop\Entity\Language;
use Newscoop\Entity\Playlist;
class Admin_PlaylistController extends Zend_Controller_Action
{
    /**
     * @var Newscoop\Entity\Repository\PlaylistRepository
     */
    private $playlistRepository = NULL;

    /**
     * @var Newscoop\Entity\Repository\PlaylistArticleRepository
     */
    private $playlistArticleRepository = NULL;

    public function init()
    {
        $this->playlistRepository = $this->_helper->entity->getRepository('Newscoop\Entity\Playlist');
        $this->playlistArticleRepository = $this->_helper->entity->getRepository('Newscoop\Entity\PlaylistArticle');
        $this->_helper->contextSwitch
            ->addActionContext( 'list-data', 'json' )
            ->addActionContext( 'save-data', 'json' )
            ->addActionContext( 'delete', 'json' )
            ->initContext();
    }

    /**
     * Playlist admin landing screen
     */
    public function indexAction()
    {
        $this->view->playlists = $this->playlistRepository->findAll();
    }

    public function popupAction()
    {
        $this->_helper->layout->setLayout('iframe');
        $playlist = $this->playlistRepository->find($this->_request->getParam('id', null));
        if ($playlist instanceof \Newscoop\Entity\Playlist)
        {
            $this->view->playlistName = $playlist->getName();
            $this->view->playlistId = $playlist->getId();
        }
    }

    public function articleAction()
    {
        $articleRepo = $this->_helper->entity->getRepository('Newscoop\Entity\Article');
        $this->view->article = current( $articleRepo->findBy( array( "number" => $this->_getParam('id')) ) );
        $this->view->playlists = $this->playlistRepository->findAll();
        $this->_helper->layout->setLayout('iframe');
    }

    public function deleteAction()
    {
        $id = $this->_request->getParam('id');
        $this->view->id = $id;
        $this->playlistRepository->delete($this->playlistRepository->find($id));
    }

    public function listDataAction()
    {
        $playlist = new Playlist();
        $lang = null;
        if (isset($_SESSION['f_language_selected']))
        {
            $lang = new Language();
            $lang->setId((int)$_SESSION['f_language_selected']);
        }

        $playlist->setId($this->_request->getParam('id'));
        $this->view->items = $this->playlistRepository->articles($playlist, $lang);
        $this->view->code = 200;
    }

    public function saveDataAction()
    {
        $playlistId = $this->_request->getParam('id', null);
        $playlist = null;
        $playlistName = $this->_request->getParam('name', '');
        // TODO make a service
        if (is_numeric($playlistId))
        {
            $playlist = $this->playlistRepository->find($playlistId);
            if (!is_null($playlist) && trim($playlistName)!='') {
                $playlist->setName($playlistName);
            }
        }
        else
        {
            $playlist = new Playlist();
            $playlist->setName(trim($playlistName)!='' ? $playlistName:getGS('Playlist').strftime('%F') );
        }
        $playlist = $this->playlistRepository->save($playlist, $this->_request->getParam('articles'));
        if (!($playlist instanceof \Exception))
        {
            $this->view->playlistId = $playlist->getId();
            $this->view->playlistName = $playlist->getName();
        }
        else {
            $this->view->error = $playlist->getFile().":".$playlist->getLine()." ".$playlist->getMessage();
        }
    }
}