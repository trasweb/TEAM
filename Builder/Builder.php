<?php

/**
New Licence bsd:
Copyright (c) <2012>, Manuel Jesus Canga MuÃ±oz
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:
 * Redistributions of source code must retain the above copyright
notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright
notice, this list of conditions and the following disclaimer in the
documentation and/or other materials provided with the distribution.
 * Neither the name of the trasweb.net nor the
names of its contributors may be used to endorse or promote products
derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL Manuel Jesus Canga MuÃ±oz BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

 */

namespace Team\Builder;


\Team\Loader\Classes::add('\Team\Builder\Gui', 			'/Builder/Gui.php', _TEAM_);
\Team\Loader\Classes::add('\Team\Builder\Actions', 		'/Builder/Actions.php', _TEAM_);
\Team\Loader\Classes::add('\Team\Builder\Commands', 	'/Builder/Commands.php', _TEAM_);

/**
Clase que se encargará de construcción de la acción para su lanzamiento
Usamos el patrón Template Method un poco adaptado a lo que necesitamos
 */
abstract class Builder implements \ArrayAccess {
    use \Team\Data\Box;

    protected $base;

    abstract protected function getTypeController();
    abstract protected function checkParent($class);
    abstract protected function sendHeader();

    public function __construct($params = []) {
        $this->data = $params;

        \Team\System\Context::set('CONTROLLER_BUILDER', $this);
        \Team\System\Context::set('CONTROLLER_TYPE', $this->getTypeController() );


        if(\Team\System\Context::isMain()  ){
            $APP = \Team\System\Context::get('APP');

            if($APP ) {
                \Team\System\FileSystem::ping('/'.$APP.'.php', \Team\_CONFIG_);
            }
        }
    }

    /**
    Asignamos el paquete
     */
    protected function setApp($app) {

        $this->base = \Team\Config::get(strtoupper($app).'_APP_PATH', _APPS_.'/'.$app);

        if('theme' == $app){
            $this->base = _SCRIPTS_.\Team\Config::get('_THEME_');
        }


        if(!file_exists($this->base) ) {
            \Team::system("App '{$app}' not found", '\team\responses\Response_Not_Found');
        }

        \Team\System\Context::set('NAMESPACE', '\\'.$app);
        $this->namespace = '\\';

        $this->setContext('APP', $app);
        $this->setContext('_APP_', $this->base);
        $this->setContext('BASE','/'.$app);

        $this->app = $app;


        //Preparamos los datos para filtrar
        $data = new \Team\Data\Data($this->data);
        if($this['is_main']) {
            $data = new \Team\Data\Type\Url(null, [], $data->get());
        }

        //Vamos a mandar un filtro de personalización de argumentos. Por si un package quiere personalizar sus argumentos( por ejemplo, acorde a la url de entrada )
        $data = \Team\Data\Filter::apply('\team\builder_data', $data);

        $this->set($data->get() );
    }

    /**
    Asignamos el componente
     */
    protected function setComponent($component) {
        $component = \Team\Data\Sanitize::identifier($component);

        if(empty($component)) {
            \Team::system("Component in app '{$this->app}' not specified", '\team\responses\Response_Not_Found', $this->get(), $level = 5);

        }

        //Pasamos al namespace actual
        $this->namespace = "\\{$this->app}\\{$component}";
        //Guardamos el path a la acción tanto absoluta como relativamente
        $this->path = str_replace("\\", "/", $this->namespace);

        //Tendriamos que comprobar que existe el directorio del componente
        \Team\System\Context::set('NAMESPACE', $this->namespace);

        //Guardamos los datos de componente
        $this->setContext('APP', $this->app);
        $this->setContext('_APP_', $this->base);
        $this->setContext('COMPONENT', $component);
        $this->setContext('_COMPONENT_', $this->base.'/'.$component);
        $this->setContext('BASE', '/'.$this->app.'/'.$component);
        $this->setContext('BASE_URL',  \Team\System\Context::get('_AREA_').'/'.$component.'/');

        $this->component = $component;
    }


    /**
    Se parsea la respuesta y asignamos una válida
     */
    protected function setResponse($response) {
        $response = \Team\Data\Sanitize::identifier($response);
        $response = strtolower($response);

        //Default template is when response is wrong or empty and notfound_response is when response doesn't exit
        $response = (empty($response) || ("_" == $response[0]))?  \Team\Data\Filter::apply('\team\default_response', 'index', $response) : $response;

        //Tendriamos que comprobar que existe el directorio o la clase de la acción
        \Team\System\Context::set("RESPONSE", $response);
        $self = \Team\System\Context::get('BASE_URL').$response.'/';

        if($this->id){
            $self .= $this->id.'/';
        }

        //Se diferencia de _SELF_ en que que SELF es es dependiente del contexto y _SELF_ es la que se llamó por el usuario
        \Team\System\Context::set('SELF', $self);


        $this->response = $response;
    }


    /**
    Añadimos el valor de una variable al contexto actual y la convertimos en contantes sino hubiera sido ya creada
    sirve de helper para setComponent
     */
    private function setContext($var, $value) {
        if(empty($var) ) return ;

        $namespace = \Team\Data\Sanitize::trim($this->namespace, '\\');

        $constant_name = ltrim($namespace.$var, '\\');

        if( !defined($constant_name) ) {
            define($constant_name, $value);
        }

        \Team\System\Context::set($var, $value);
    }

    /**
    Asignamos el nombre de la clase de la acción que se lanzará
     */
    protected function checkController() {

        //Añadimos y filtramos los datos de la acción
        $this->setApp($this->app );
        $this->setComponent($this->component);
        $this->setResponse($this->response);

        //Ej de nombre de clase de tipo gui:  /web/news/Gui
        $this->controller = $this->getController($this->response);
        $this->controller = \Team\Data\Filter::apply('\team\builder\controller', $this->controller);

        $class_exists = class_exists($this->controller);

        if(!$class_exists) {

            $class_file = '/'.$this->component.'/'.$this->getTypeController().'.php';

            if( ! \Team\Loader\Classes::load($this->controller, $class_file, $this->base)  )  {
                return \Team::system("Controller class file {$class_file} not found", '\team\responses\Response_Not_Found');
            }
        }

        $is_a_controller = is_subclass_of($this->controller, '\team\controller\Controller');

        if(!$this->checkParent($this->controller)) {
            return \Team::system("Controller class {$this->controller} hasn't got a good parent. Check it", '\team\responses\Response_Not_Found');
        }

        if(!$is_a_controller) {
            return \Team::system("Controller class {$this->controller} isn't a \\team\\responses\\controller. Check namespaces and names", '\team\responses\Response_Not_Found');
        }


        return true;
    }

    /** Devolvemos el nombre de la clase controladora */
    protected function getController($response = null) {
        return "{$this->namespace}\\".$this->getTypeController();
    }


    /**
    Checkeamos si existe un método que recoja la accción pedida
    Hay que recordar que las peticiones main sólo pueden acceder a métodos públicos que:
    - no sean estáticos
    - que su identificador empiecen por _
    - que sea una llamada a 'commons'
    - que no sean pertenecientes a clases del namespace  team.
    Las peticiones no main son igual que las main, pero también pueden acceder a los métodos protected.
    En los métodos private no podrán entrar.
     */
    protected function getMethod($class,  $response, $reflection_class,  $is_default = false) {
        //we check if method exists
        $response_exists = method_exists($class, $response) && "commons" != $response && '_'  != $response[0];
        //Nos aseguramos que es un método de los definidos por el usuario y no de las clases padres
        //Seguridad siempre
        if($response_exists) {
            $method  =  $reflection_class->getMethod($response);

            //Sólo se permite lanzar métodos directos(de la clase de componente ) o derivados, pero no de los métodos de las clases de team )
            //ejemplo, no se permite un response como addCss o get() por ser de team/Controller/*
            //Sin embargo cualquier otro response del Controller del usuario sí se permite ( incluso si esta derivada de otra clase suya )
            $not_team = strpos(trim($method->class, '\\'), 'team');

            //No se permite tampoco métodos estáticos o no públicos
            $response_exists = $not_team !== 0 && !$method->isStatic();

            //Protected methods of Controllers only can be acccesible for same package
            if($response_exists)

                if( $this->app == \Team\System\Context::before('APP') && $method->isProtected() ) {
                    //Supuestamente ya se ha lanzado el response de main, por tanto, no hay peligro de hacer el método accesible
                    //No hay dos peticiones main diferenes.
                    $method->setAccessible( true );

                }else if(  !is_callable([$class, $response]) ||  !$method->isPublic() ) {
                    $response_exists = false;
                }else {
                    //Not problem with method.
                }
        }

        if(!$is_default && !$response_exists) {
            //Si no se encuentra o no existe respuesta, se tomará como response el response por defecto 'noencontrada'
            $response = \Team\Data\Filter::apply('\team\notfound_response', 'main', $response, $class ) ;
            return $this->getMethod($class, $response, $reflection_class, $is_default = true);
        }

        if( !$response_exists  ) {
            return \Team::system("Response method '{$this->response}' in Controller class $class  not found", '\team\responses\Response_Not_Found', $this->get(), $level = 5 );
        }

        return $method;
    }



    protected function dispatch($class, $method, $response) {

        $class->___load($response);

        if(\team\Config::get('TRACE_REQUESTS') ) {
            \Team\Debug::me("{$class}->{$method}()", "Cargando response.");
            $this->get()->debug("Params");
        }

        //Launch response
        $result = $method->invoke($class, $response);

        //Llamamos a las tareas de finalizacion
        return  $class->___unload($result,$response);
    }




//Para lanzar una acción sería:
//$component = new /package/micomponente();
//$component->var1 = valor1;
//$component->list(); //ej: $noticia->list();   

    public function buildResponse() {
        \Team::up();

        $result = NULL; //Resultado de lanzar la accion

        try {
            //Aqui toca asignar el nombre de la acción y también cmprobar su existencia
            if(! $this->checkController() ) {
                \Team::system("Component class '{$this->controller}' not found", '\team\responses\Response_Not_Found');
            }

            \Team\Debug::trace("Preparando el lanzamiento de la nueva ación in Builder.php", $this->controller);

            $class = $this->controller;

            //Todas las constantes públicas que tenbga la clase las usamos como variables de contexto
            $reflection_class = new \ReflectionClass($class);
            \Team\System\Context::defaults($reflection_class->getConstants() );

            $method = $this->getMethod($class, $this->response, $reflection_class);


            //-------- Nos preparamos para lanzar la accion -------------
            $controller = new $class($this->get(), $this->response );


            $result = $this->dispatch($controller,$method, $this->response);

            //obtenemos el listado de nuevas variables definidas en la accion
            $data = $controller->getDataObj();

            $this->checkErrors($data, $result);


            //Lanzamos la transformación ( Lo podriamos hacer desde Component, primero lanzamos la acción y luego lanzamos la vista )
            \Team\System\Context::set('TRANSFORMING_RESPONSE_DATA', true);

            $result = $this->transform($data, $controller, $result);


            //Se ha detectado un error de sistema. Don't worry. Be happy
        }catch(\Throwable $SE) {
            $controller =  $controller?? null;
            $result = $result?? null;

            $result = $this->error($SE, $controller, $result);
        }



        \Team\System\Context::set('TRANSFORMING_RESPONSE_DATA', false);

        if(($this->is_main || 1 ==  \Team\System\Context::getLevel() ) ) {
            $this->header();
        }


        //Event('Post_Action', '\team\actions')->ocurred($event_data);

        //Eliminamos el objeto de la accion
        unset($controller);



        \Team::down();


        //Devolvemos el resultado
        return $result;

    }

    /**
    Se produjo un error durante la creación o la ejecución de la acción
     */
    protected function error($SE, $controller = null, $result = '') {

        $msg = "[".\Team\System\Context::get('NAMESPACE')."]: ".$SE->getMessage();

        //Si no es main, se debería de volver al nivel inferior devolviendo '' con un error ( para que sepan que algo pasó )
        if(!$this->is_main || 'Gui' != $this->getTypeController() ) {

            //El controlador tiene opción de manejar sus errores como quiera
            //Por ejemplo, podría haber un error en el acceso a la base de datos al mostrar un widget
            //y el controllador del widget podría no querer lanzar ningún error pero sí devolver el mensaje: "Widget no cargado" o simplemente no mostrar nada.
            if(isset($this->controller) && method_exists($this->controller, 'onError') ){
                return $this->controller::onError($SE, $result);
            }

            //A pesar de que no sea main, y que el controllador no se hizo cargo, tenemos que avisar a los niveles inferiores de que hubo un error.
            \Team::error($msg, $SE->getCode(), $SE, $SE->getFile(), $SE->getLine());
            return ' ';
        }else {
            \Debug::me($msg, 'ERROR', $SE->getFile(), $SE->getLine() );
        }


        //Si es main cada tipo de Controller manejara el error a su manera
        return $this->getCriticalError($SE);
    }


    protected function header() {

        //Guardamos toda la salida que se haya hecho al navegador hasta ahora
        $ob_out = ob_get_contents();
        if(''!= $ob_out )
            ob_clean();

        $this->sendHeader();


        //Si se ha especificado que no se muestren errores
        //Evitamos cualquier salida de error o de echos o de lo que sea
        //Tan solo dejamos mostrar la vista
        if(\team\Config::get("SHOW_EXTRA", true)) {
            echo $ob_out;
        }
    }

    protected function sendHeaderHTTP($type) {

        //Fix bug in firefox
        if(!headers_sent() ) {

            $header = "Content-Type: $type; charset=".\team\Config::get("CHARSET");

            $header = \Team\Data\Filter::apply('\team\header', $header);

            header($header, true);
        }

    }


}