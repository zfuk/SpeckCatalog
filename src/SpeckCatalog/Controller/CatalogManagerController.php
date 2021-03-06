<?php

namespace SpeckCatalog\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\Paginator\Paginator;
use Zend\Paginator\Adapter\ArrayAdapter as ArrayAdapter;
use Zend\Stdlib\Hydrator\ClassMethods as Hydrator;

class CatalogManagerController extends AbstractActionController
{
    protected $partialDir = '/speck-catalog/catalog-manager/partial/';

    public function categoryTreePreviewAction()
    {
        $siteId = $this->params('siteid');
        $categoryService = $this->getService('category');
        $categories = $categoryService->getCategoriesForTreePreview($siteId);

        $viewVars = array('categories' => $categories);
        return $this->partialView('category-tree', $viewVars);
    }

    //find categories/products that match search terms
    public function categorySearchChildrenAction()
    {
        $type = $this->params('type');
        $children = $this->getService($type)->getAll();

        $viewVars = array('children' => $children, 'type' => $type);
        return $this->partialView('category-search-children', $viewVars);
    }

    public function newProductAction()
    {
        $product = $this->getService('product')->getModel();
        return new ViewModel(array('product' => $product));
    }

    public function productsAction()
    {
        $products = $this->getService('product')->getAll();
        return new ViewModel(array('products' => $products));
    }

    public function categoriesAction()
    {
        $sites = $this->getService('sites')->getAll();
        return new ViewModel(array('sites' => $sites));
    }

    public function productAction()
    {
        $productService = $this->getService('product');
        $product = $productService->getFullProduct($this->params('id'));

        $vars = array('product' => $product);
        return new ViewModel($vars);
    }


    //returns main view variable(product/option/etc)
    protected function persist($class, $form)
    {
        $service = $this->getService($class);

        if (method_exists($service, 'persist')) {
            return $service->persist($form);
        }

        $originalData = $form->getOriginalData();
        $data = $form->getData();

        if (count($originalData) && $service->find($originalData)) {
            $service->update($data, $originalData);
            return $service->find($data, true, true);
        }

        return $service->insert($data);
    }

    public function updateProductAction()
    {
        $formData = $this->params()->fromPost();
        $form = $this->getService('form')->getForm('product', null, $formData);

        if ($form->isValid()) {
            $product = $this->persist('product', $form);
            return $this->getResponse()->setContent($product->getProductId());
        }

        return $this->partialView('product', array('product' => $product));
    }

    public function updateRecordAction()
    {
        $class = $this->params('class');
        $formData = $this->params()->fromPost();
        $form = $this->getService('form')->getForm($class, null, $formData);

        if ($form->isValid()) {
            $entity = $this->persist($class, $form);
        } else {
            $entity = $this->getService($class)->getEntity($formData);
        }

        $partial = $this->dash($class);
        $viewVars = array(lcfirst($this->camel($class)) => $entity);
        return $this->partialView($partial, $viewVars);
    }

    public function updateFormAction()
    {
        $class = $this->params('class');
        $serviceLocator = $this->getServiceLocator();
        $formService = $this->getService('form');
        $formData = $this->params()->fromPost();
        $form = $formService->getForm($class, null, $formData);
        $viewHelperManager = $serviceLocator->get('viewhelpermanager');
        $formViewHelper = $viewHelperManager->get('speckCatalogForm');
        $messageHtml = $formViewHelper->renderFormMessages($form);

        $response = $this->getResponse()->setContent($messageHtml);
        return $response;
    }

    public function findAction()
    {
        $postParams = $this->params()->fromPost();

        $models = array();
        if(isset($postParams['query'])) {
            $models = $this->getService($postParams['parent_name'])->search($postParams['query']);
        }

        $view = new ViewModel(array('models' => $models, 'fields' => $postParams));
        $view->setTemplate($this->partialDir . 'find-models')->setTerminal(true);
        return $view;
    }

    public function foundAction()
    {
        $postParams = $this->params()->fromPost();

        $objects = array();

        if ($postParams['child_name'] === 'builder_product') {
            $parentProductId = $postParams['parent']['product_id'];
            $productIds = array_keys($postParams['check']);
            foreach ($productIds as $productId) {
                $objects[] = $this->getService('builder_product')->newBuilderForProduct($productId, $parentProductId);
            }
        }

        $viewHelperManager = $this->getServiceLocator()->get('viewhelpermanager');
        $viewHelper = $viewHelperManager->get('speckCatalogRenderChildren');
        $content = $viewHelper->__invoke($postParams['child_name'], $objects);
        $response = $this->getResponse()->setContent($content);
        return $response;
    }

    public function sortAction()
    {
        $postParams = $this->params()->fromPost();
        $childName  = $this->params('type');
        $parentName = $this->params('parent');
        $parent     = $postParams['parent_key'];

        $order = explode(',', $postParams['order']);
        foreach ($order as $i => $val) {
            if (!trim($val)) {
                unset($order[$i]);
            }
        }
        $parentService = $this->getService($parentName);
        $sortChildren = 'sort' . $this->camel($childName) . 's';
        $result = $parentService->$sortChildren($parent, $order);

        return $this->getResponse();
    }

    public function removeChildAction()
    {
        $postParams = $this->params()->fromPost();
        $parentName = $postParams['parent_name'];
        $childName  = $postParams['child_name'];
        $parent     = $postParams['parent'];
        $child      = $postParams['child'];

        $parentService = $this->getService($parentName);

        $removeChildMethod = 'remove' . $this->camel($childName);
        $result = $parentService->$removeChildMethod($parent, $child);

        if (true === $result) {
            return $this->getResponse()->setContent('true');
        }
        return $this->getResponse()->setContent('false');
    }

    public function getService($name)
    {
        $serviceName = 'speckcatalog_' . $name . '_service';
        return $this->getServiceLocator()->get($serviceName);
    }

    //return the partial for a new record.
    public function newPartialAction()
    {
        $postParams = $this->params()->fromPost();
        $parentName = $postParams['parent_name'];
        $childName  = $postParams['child_name'];
        $parent     = $postParams['parent'];

        $parent = $this->getService($parentName)->find($parent);
        $child  = $this->getService($childName)->getModel();

        $child->setParent($parent);

        $partial  = $this->dash($childName);
        $viewVars = array(lcfirst($this->camel($childName)) => $child);
        return $this->partialView($partial, $viewVars);
    }

    public function partialView($partial, array $viewVars=null)
    {
        $view = new ViewModel($viewVars);
        $view->setTemplate($this->partialDir . $partial);

        $view->setTerminal(true);

        //$rend = $this->getServiceLocator()->get('zendviewrendererphprenderer');
        //$html = $rend->render($view);
        //return $this->getResponse()->setContent($html);



        return $view;
    }

    protected function dash($name)
    {
        $dash = new \Zend\Filter\Word\UnderscoreToDash;
        return $dash->__invoke($name);
    }

    protected function camel($name)
    {
        $camel = new \Zend\Filter\Word\UnderscoreToCamelCase;
        return $camel->__invoke($name);
    }
}
