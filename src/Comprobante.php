<?php

/**
 * Interfaz para procesar los comprobantes electronicos
 *
 * PHP version 7.4
 *
 * @category  Facturacion-electronica
 * @package   Contica\Facturacion
 * @author    Josias Martin <josias@solucionesinduso.com>
 * @copyright 2020 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @version   GIT: <git-id>
 * @link      https://github.com/josiasmc/facturador-electronico-cr
 */

namespace Contica\Facturacion;

use GuzzleHttp\Client;
use GuzzleHttp\Exception;
use Sabre\Xml\Service;
use League\Flysystem\{FilesystemException, UnableToReadFile, UnableToWriteFile};

/**
 * Class providing functions to manage electronic invoices
 *
 * @category Facturacion-electronica
 * @package  Contica\Facturacion\Comprobante
 * @author   Josias Martin <josias@solucionesinduso.com>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturador-electronico-cr
 */
class Comprobante
{
    protected $container;
    protected $id;    //ID unico de la empresa
    public $clave;    // clave del comprobante
    public $estado;   // estado: 1=En cola, 2=Enviado, 3=Aceptado 4=Rechazado 5=En cola con error de envio
    protected $datos; // la informacion del comprobante
    protected $xml;   //XML del comprobante
    protected $tipo;  //E para emision, o R para recepcion
    protected $id_comprobante; //id_emision o id_recepcion
    protected $consecutivo = ''; //Consecutivo del comprobante
    public $enHacienda = null; //Utilizado al consultar estado de comprobante a recepcionar

    public const EMISION = 1;   //Comprobante de emision
    public const RECEPCION = 2; //Compbrobante de recepcion

    /**
     * Constructor del comprobante
     *
     * @param array $container   El contenedor del facturador
     * @param array $datos       Los datos del comprobante a crear o cargar
     * @param int   $id          El ID unico de la empresa emisora
     * @param bool  $sinInternet True para un comprobante que se genero sin conexion
     */
    public function __construct($container, $datos, $id, $sinInternet = false)
    {
        date_default_timezone_set('America/Costa_Rica');
        $this->container = $container;

        if (isset($datos['clave']) && isset($datos['tipo'])) {
            //Cargar un comprobante que ya esta hecho
            $clave = $datos['clave'];
            $tipo = $datos['tipo']; //E = emision, R = recepcion, C = consulta en Hacienda
            if ($tipo === 'E') {
                $tabla = 'fe_emisiones';
                $id_name = 'id_emision';
            } elseif ($tipo === 'R') {
                $tabla = 'fe_recepciones';
                $id_name = 'id_recepcion';
            } elseif ($tipo === 'C') {
                $this->clave = $clave;
                $this->tipo = $tipo;
                $this->id = $id;
                $this->estado = 2;
                return;
            }

            $db = $container['db'];

            $stmt = $db->prepare(
                "SELECT estado, $id_name FROM $tabla
                WHERE clave=?"
            );
            $stmt->bind_param('s', $clave);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                //Existe
                $r = $result->fetch_row();
                $this->estado = $r[0];
                $this->id_comprobante = $r[1];
                $this->consecutivo = substr($clave, 21, 20);
                $this->clave = $clave;
                $this->tipo = $tipo;
                $this->id = $id;
            } else {
                //No existe
                throw new \Exception('El comprobante no existe');
            }
        } else {
            //-------- Crear un nuevo comprobante --------
            if (isset($datos['NumeroConsecutivoReceptor'])) {
                //Crear un mensaje de confirmacion nuevo
                $this->consecutivo = $datos['NumeroConsecutivoReceptor'];
                $this->tipo = 'R';
            } else {
                //Crear un comprobante nuevo
                $cedula = $datos['Emisor']['Identificacion']['Numero'];
                $this->consecutivo = $datos['NumeroConsecutivo'];
                $this->tipo = 'E';
            }
            if (isset($datos['Clave'])) {
                $clave = $datos['Clave'];
            }

            //---Revisar si el emisor esta guardado---
            $empresas = new Empresas($container);
            if (!$empresas->get($id)) {
                throw new \Exception('El emisor no esta registrado');
            };

            //---Probar si enviaron consecutivo bueno---
            if (strlen($this->consecutivo) !== 20) {
                throw new \Exception('El consecutivo no tiene la correcta longitud');
            }

            //---Especificar la situacion---
            if ($sinInternet) {
                $situacion = 3; //Sin internet
            } else {
                $situacion = 1; //Normal
            }
            if (isset($datos['InformacionReferencia'])) {
                // Revisar a ver si viene una referencia del tipo de documento de contingencia
                $arrayKeys = array_keys($datos['InformacionReferencia']);
                $ref = null;
                if (is_numeric($arrayKeys[0])) {
                    // Este documento tiene multiples referencias
                    foreach ($datos['InformacionReferencia'] as $referencia) {
                        // Revisar cada referencia
                        if ($referencia['TipoDoc'] == '08') {
                            $ref = $referencia;
                            break;
                        }
                    }
                } else {
                    $ref = $datos['InformacionReferencia'];
                }
                if ($ref && $ref['TipoDoc'] == '08') {
                    $situacion = 2; //Contingencia
                }
            }

            //---Generar clave si no existe---
            if (!isset($datos['Clave'])) {
                //Generar la clave numerica
                $pais = '506';
                $fecha = date('dmy');
                $cedula12 = str_pad($cedula, 12, '0', STR_PAD_LEFT);
                $codigo = rand(10000000, 99999999);
                $clave =  $pais . $fecha . $cedula12 . $this->consecutivo . $situacion . $codigo;
                if (strlen($clave) !== 50) {
                    throw new \Exception('La clave no tiene la correcta longitud');
                }
                $datos = array_merge(['Clave' => $clave], $datos);
            }
            $this->id = $id;
            $this->datos = $datos;
            $this->estado = 0; //Comprobante sin guardar
            $this->clave = $clave;

            //---Generar el xml de este comprobante
            $this->container['id'] = $id; //Guardar el id de empresa
            $creadorXml = new CreadorXML($this->container);
            $this->xml = $creadorXml->crearXml($datos);
        }
    }

    /**
     * Guardar el comprobante en la cola
     *
     * @return bool
     */
    public function guardarEnCola()
    {
        if ($this->estado > 0) {
            return false;
        }
        $db = $this->container['db'];
        $clave = $this->clave;
        $this->estado = 1; //En cola
        if ($this->tipo == 'R') {
            $table = 'fe_recepciones';
            $accion = 2; //ENVIAR_RECEPCION
        } else {
            $table = 'fe_emisiones';
            $accion = 1; //ENVIAR_EMISION
        }
        $this->generateFilenames($zip_path, $zip_name, $filename);

        //Guardar el archivo XML
        $filesystem = $this->container['filesystem'];
        $zip = new \ZipArchive();
        $tmpfile = sys_get_temp_dir() . '/' . $zip_name;
        if ($this->tipo == 'R' && $filesystem->fileExists($zip_path . $zip_name)) {
            //Abrimos el existente y le añadimos los archivos
            try {
                $contents = $filesystem->read($zip_path . $zip_name);
            } catch (FilesystemException | UnableToReadFile $exception) {
                throw new \Exception("No se pudo abrir el archivo zip para el documento $filename.\n");
            }

            if (file_put_contents($tmpfile, $contents) === false) {
                throw new \Exception("Fallo al guardar el archivo temporal\n");
            }

            if ($zip->open($tmpfile) !== true) {
                throw new \Exception("Fallo al abrir <$zip_name> guardando MR\n");
            }
        } else {
            //Creamos uno nuevo
            if ($zip->open($tmpfile, \ZipArchive::CREATE) !== true) {
                throw new \Exception("Fallo al abrir <$zip_name>\n");
            }
        }
        $zip->addFromString($filename, $this->xml);
        $zip->close();

        $contents = file_get_contents($tmpfile);
        if ($contents === false) {
            throw new \Exception("Fallo al leer el archivo temporal\n");
        }

        if (strlen($contents) == 0) {
            throw new \Exception("El archivo XML esta vacio\n");
        }

        unlink($tmpfile);
        try {
            $filesystem->write($zip_path . $zip_name, $contents);
        } catch (FilesystemException | UnableToWriteFile $exception) {
            throw new \Exception("Fallo al guardar el archivo <$zip_name>\n");
        }

        // Buscar a ver si existe el registro
        $sql = "SELECT COUNT(*) FROM $table
        WHERE clave='$clave' AND id_empresa={$this->id}";
        $res = $db->query($sql);
        if ($res->num_rows) {
            $hasRecord = $res->fetch_row()[0] > 0;
            if ($hasRecord) {
                // Eliminar los registros primero
                $sql = "DELETE FROM $table
                WHERE clave='$clave' AND id_empresa={$this->id}";
                $db->query($sql);
                $sql = "DELETE FROM fe_cola
                WHERE clave='$clave' AND id_empresa={$this->id} AND accion=$accion";
                $db->query($sql);
            }
        }

        //Guardar el registro
        $sql = "INSERT INTO $table (clave, id_empresa, estado)
        VALUES ('$clave', '{$this->id}', '{$this->estado}')";
        $db->query($sql);
        $this->id_comprobante = $db->insert_id;

        //Agregar a la cola
        $timestamp = (new \DateTime())->getTimestamp();
        $sql = "INSERT INTO fe_cola (id_empresa, clave, accion, tiempo_creado, tiempo_enviar)
        VALUES ('{$this->id}', '$clave', $accion, $timestamp, $timestamp)";
        $db->query($sql);

        return true;
    }

    /**
     * Generar ruta de zip y de comprobante xml
     *
     * @return bool true si se logra
     */
    private function generateFilenames(&$path, &$zipname, &$filename)
    {
        $clave = $this->clave;
        $path = "{$this->id}/20" . \substr($clave, 7, 2) . \substr($clave, 5, 2) . '/';

        if ($this->tipo == 'R') {
            $zipname = 'R' . $clave . '.zip';
            $tipo_doc = 'MR';
        } else {
            $zipname = 'E' . $clave . '.zip';
            $tipo_doc = substr($this->consecutivo, 9, 1) - 1;
            $tipo_doc = ['FE', 'NDE', 'NCE', 'TE', 'MR', 'MR', 'MR', 'FEC', 'FEE'][$tipo_doc];
        }
        $filename = $tipo_doc . $clave . '.xml';
        return true;
    }

    /**
     * Cargar datos del xml guardado
     *
     * @return bool true si se logra
     */
    private function cargarDatosXml()
    {
        $this->generateFilenames($zip_path, $zip_name, $filename);
        //Abrir el archivo XML
        $filesystem = $this->container['filesystem'];
        $tmpfile = sys_get_temp_dir() . '/' . $zip_name;
        try {
            $contents = $filesystem->read($zip_path . $zip_name);
            file_put_contents($tmpfile, $contents);
        } catch (FilesystemException | UnableToReadFile $exception) {
            throw new \Exception("No se pudo leer el archivo $zip_name");
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpfile) !== true) {
            throw new \Exception("Fallo al abrir <$zip_name>\n");
        }
        if ($zip->locateName($filename) !== false) {
            //XML esta guardado
            $this->xml = $zip->getFromName($filename);
            $this->datos = Comprobante::analizarXML($this->xml);
            $zip->close();
            unlink($tmpfile);
            return true;
        }
        $zip->close();
        unlink($tmpfile);
        return false;
    }

    /**
     * Enviar el comprobante a Hacienda
     *
     * @return bool
     */
    public function enviar()
    {
        if ($this->estado == 0) {
            //no se ha guardado todavia
            $this->guardarEnCola();
        }

        if (!$this->datos) {
            $this->cargarDatosXml();
        }
        $datos = $this->datos;
        if ($datos) {
            $idEmpresa = $this->id;
            $rateLimiter = $this->container['rate_limiter'];
            if (!$rateLimiter->canPost($idEmpresa)) {
                // Limite exedido para esta cedula
                return false;
            }
            // Intentar conseguir un token de acceso
            $token = (new Token($this->container, $idEmpresa))->getToken();
            if ($token) {
                //Tenemos token, entonces intentamos hacer el envio
                if ($this->tipo == 'E') {
                    //Cogemos datos para una factura
                    $clave = $datos['Clave'];
                    $accion = 1; //enviar emision
                    $table = 'fe_emisiones';
                    $post = [
                        'clave' => $clave,
                        'fecha' => $datos['FechaEmision'],
                        'emisor' => [
                            'tipoIdentificacion' => $datos['Emisor']['Identificacion']['Tipo'],
                            'numeroIdentificacion' => $datos['Emisor']['Identificacion']['Numero']
                        ]
                    ];
                    if (isset($datos['Receptor']['Identificacion'])) {
                        $post['receptor'] = [
                            'tipoIdentificacion' => $datos['Receptor']['Identificacion']['Tipo'],
                            'numeroIdentificacion' => $datos['Receptor']['Identificacion']['Numero']
                        ];
                    }
                } else {
                    //Cogemos datos para un mensaje receptor
                    $cedula = ltrim($datos['NumeroCedulaReceptor'], '0');
                    $clave = $datos['Clave'];
                    if (strlen($cedula == 9)) {
                        $tipoId = '01';
                    } elseif (strlen($cedula > 10)) {
                        $tipoId = '03';
                    } elseif (preg_match("/^[234][\d]{9}$/", $cedula) == 1) {
                        $tipoId = '02';
                    } else {
                        $tipoId = '04';
                    }
                    //Conseguir el archivo XML recepcionado
                    $path = "$idEmpresa/" . "20" . \substr($clave, 7, 2) . \substr($clave, 5, 2) . '/';
                    $zip_name = 'R' . $clave . '.zip';
                    $tipo_doc = substr($clave, 30, 1) - 1;
                    $tipo_doc = ['FE', 'NDE', 'NCE', 'TE', 'MR', 'MR', 'MR', 'FEC', 'FEE'][$tipo_doc];
                    $filename = $tipo_doc . $clave . '.xml';

                    $filesystem = $this->container['filesystem'];
                    $tmpfile = sys_get_temp_dir() . '/' . $zip_name;
                    try {
                        $contents = $filesystem->read($path . $zip_name);
                        file_put_contents($tmpfile, $contents);
                    } catch (FilesystemException | UnableToReadFile $exception) {
                        throw new \Exception("No se pudo leer el archivo $zip_name");
                    }

                    $zip = new \ZipArchive();
                    if ($zip->open($tmpfile) !== true) {
                        throw new \Exception("Fallo al abrir <$zip_name> para enviar MR\n");
                    }

                    if ($zip->locateName($filename) !== false) {
                        $xmlData = Comprobante::analizarXML($zip->getFromName($filename));
                    } else {
                        $zip->close();
                        unlink($tmpfile);
                        return false;
                    }
                    unlink($tmpfile);
                    $zip->close();

                    $table = 'fe_recepciones';
                    $accion = 2; //enviar recepcion
                    $post = [
                        'clave' => $clave,
                        'fecha' => $xmlData['FechaEmision'],
                        'emisor' => [
                            'tipoIdentificacion' => $xmlData['Emisor']['Identificacion']['Tipo'],
                            'numeroIdentificacion' => $xmlData['Emisor']['Identificacion']['Numero']
                        ],
                        'receptor' => [
                            'tipoIdentificacion' => $tipoId,
                            'numeroIdentificacion' => $cedula
                        ]
                    ];
                }
                $callbackUrl = $this->container['callback_url'];
                if ($callbackUrl) {
                    $callbackUrl .= "?token={$this->tipo}{$this->id_comprobante}";
                    $post['callbackUrl'] = $callbackUrl;
                }
                if ($this->tipo == 'R') {
                    $post['consecutivoReceptor'] = $datos['NumeroConsecutivoReceptor'];
                }

                $post['comprobanteXml'] = base64_encode($this->xml);

                $sql  = "SELECT a.uri_api FROM fe_ambientes a
                LEFT JOIN fe_empresas e ON e.id_ambiente = a.id_ambiente
                WHERE e.id_empresa=$idEmpresa";
                $uri = $this->container['db']->query($sql)->fetch_row()[0] . 'recepcion';
                $client = new Client(
                    [
                        'headers' => [
                            'Authorization' => 'bearer ' . $token,
                            'Content-type' => 'application/json'
                        ]
                    ]
                );
                $post = json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                try {
                    $res = $client->post($uri, ['body' => $post]);
                    $code = $res->getStatusCode();
                    if ($code == 201 || $code == 202) {
                        // Se envio correctamente
                        $rateLimiter->registerTransaction($idEmpresa, RateLimiter::POST_202);
                        $sql = "DELETE FROM fe_cola WHERE clave='$clave' AND accion=$accion";
                        $this->container['db']->query($sql);
                        $sql = "UPDATE $table SET estado=2 WHERE clave='$clave'";
                        $this->container['db']->query($sql);
                        $this->estado = 2; //enviado
                        $this->container['log']->debug("{$this->tipo}$clave enviado. Respuesta $code");
                        return true;
                    }
                } catch (Exception\ClientException $e) {
                    // a 400 level exception occured
                    $res = $e->getResponse();
                    $code = $res->getStatusCode();
                    $error = implode(', ', $res->getHeader('X-Error-Cause'));
                    if ($code == '401' || $code == '403') {
                        //Token expirado o mal formado
                        $rateLimiter->registerTransaction($idEmpresa, RateLimiter::POST_401_403);
                        $this->container['log']->warning(
                            "Respuesta $code al enviar {$this->tipo}$clave. Error: $error"
                        );
                    } else {
                        //Error de estructura
                        $rateLimiter->registerTransaction($idEmpresa, RateLimiter::POST_40X);
                        $this->container['log']->error(
                            "Respuesta $code al enviar {$this->tipo}$clave. Error: $error"
                        );
                    }
                } catch (Exception\ServerException $e) {
                    // a 500 level exception occured
                    $code = $e->getResponse()->getStatusCode();
                    $this->container['log']->notice(
                        "Respuesta $code al enviar {$this->tipo}$clave."
                    );
                } catch (Exception\ConnectException $e) {
                    // a connection problem
                    $this->container['log']->notice(
                        "Error de conexion al enviar {$this->tipo}$clave."
                    );
                }
            }
        } else {
            // No se pudo conseguir los datos del xml
            $this->container['log']->notice(
                "No se pudieron conseguir los datos del xml al enviar {$this->tipo}$clave."
            );
        }

        // No se pudo enviar por alguna razón
        $this->aplazarEnvio();
        return false;
    }

    /**
     * Consultar estado de comprobante en Hacienda
     *
     * @return bool
     */
    public function consultarEstado()
    {
        if ($this->estado < 2) {
            //no se ha enviado todavia
            return false;
        }

        $idEmpresa = $this->id;
        $rateLimiter = $this->container['rate_limiter'];
        if ($rateLimiter->canPost($idEmpresa)) {
            //Intentar conseguir un token de acceso
            $token = (new Token($this->container, $idEmpresa))->getToken();
            if ($token) {
                if ($this->tipo == 'R') {
                    $this->cargarDatosXml();
                    $consecutivo = $this->datos['NumeroConsecutivoReceptor'];
                } else {
                    $consecutivo = false;
                }

                $clave = $this->clave;

                $client = new Client(
                    ['headers' => ['Authorization' => 'bearer ' . $token]]
                );
                $sql  = "SELECT a.uri_api FROM fe_ambientes a
                LEFT JOIN fe_empresas e ON e.id_ambiente = a.id_ambiente
                WHERE e.id_empresa=$idEmpresa";
                $uri = $this->container['db']->query($sql)->fetch_row()[0] . "recepcion/$clave";
                if ($consecutivo) {
                    $uri .= "-$consecutivo";
                }

                try {
                    $res = $client->request('GET', $uri);
                    if ($res->getStatusCode() == 200) {
                        $body = $res->getBody();
                        $token = $this->tipo . $this->id_comprobante;

                        $cuerpo = json_decode($body, true);
                        if (isset($cuerpo['respuesta-xml'])) {
                            //llego el xml con el mensaje de respuesta
                            $xml = base64_decode($cuerpo['respuesta-xml']);
                            if ($this->tipo == 'C') {
                                $datosXML = Comprobante::analizarXML($xml);
                                $estado = $datosXML['Mensaje'] == 1 ? 3 : 4;
                                $this->estado = $estado;
                                $this->enHacienda = true;
                            } else {
                                $this->guardarMensajeHacienda($xml);
                            }
                        } elseif (isset($cuerpo['ind-estado'])) {
                            //no esta la respuesta. coger el estado actual
                            $ind_estado = strtolower($cuerpo['ind-estado']);
                            if ($ind_estado == 'recibido' || $ind_estado == 'procesando') {
                                $estado = 2;
                            } elseif ($ind_estado == 'aceptado') {
                                $estado = 3;
                            } elseif ($ind_estado == 'rechazado') {
                                $estado = 4;
                            } elseif ($ind_estado == 'error') {
                                $estado = 5;
                            } else {
                                $estado = 1;
                            }
                            if ($estado > 1) {
                                $this->enHacienda = true;
                            }
                            $this->estado = $estado;
                        }
                        return true;
                    } else {
                        // ocurrio un error
                        return false;
                    }
                } catch (Exception\ClientException $e) {
                    // a 400 level exception occured
                    $res = $e->getResponse();
                    $code = $res->getStatusCode();
                    $error = implode(', ', $res->getHeader('X-Error-Cause'));
                    if ($code == '401' || $code == '403') {
                        //Token expirado o mal formado
                        $rateLimiter->registerTransaction($idEmpresa, RateLimiter::POST_401_403);
                        $this->container['log']->warning(
                            "Respuesta $code al consultar estado de {$this->tipo}$clave. Error: $error"
                        );
                    } elseif ($code == '400') {
                        //No se encuentra
                        $this->enHacienda = false;
                        $rateLimiter->registerTransaction($idEmpresa, RateLimiter::POST_40X);
                        $this->container['log']->error(
                            "Respuesta $code al consultar estado de {$this->tipo}$clave. Error: $error"
                        );
                    } else {
                        //Error de estructura
                        $rateLimiter->registerTransaction($idEmpresa, RateLimiter::POST_40X);
                        $this->container['log']->error(
                            "Respuesta $code al consultar estado de {$this->tipo}$clave. Error: $error"
                        );
                    }
                } catch (Exception\ServerException $e) {
                    // a 500 level exception occured
                    $code = $e->getResponse()->getStatusCode();
                    $this->container['log']->notice("Respuesta $code al consultar estado de {$this->tipo}$clave.");
                } catch (Exception\ConnectException $e) {
                    // a connection problem
                    $this->container['log']->notice("Error de conexion al consultar estado de {$this->tipo}$clave.");
                }
                return false;
            } else {
                //Fallo en conseguir token
                return false;
            }
        } else {
            //Limite exedido
            return false;
        }
    }

    /**
     * Coger xml del comprobante enviado a Hacienda
     *
     * @return string
     */
    public function cogerXml()
    {
        if (!$this->datos) {
            $this->cargarDatosXml();
        }
        return $this->xml;
    }

    /**
     * Coger xml de respuesta de Hacienda
     *
     * @return string
     */
    public function cogerXmlRespuesta()
    {
        $clave = $this->clave;

        //Guardar el archivo
        $storage_path = "{$this->id}/20" . \substr($clave, 7, 2) . \substr($clave, 5, 2) . '/';

        $filename = $this->tipo == 'E' ? 'MH' : 'MHMR';
        $filename .= $clave . '.xml';
        $zip_name = $this->tipo . $clave . '.zip';

        $filesystem = $this->container['filesystem'];
        $tmpfile = sys_get_temp_dir() . '/' . $zip_name;
        try {
            $contents = $filesystem->read($storage_path . $zip_name);
            file_put_contents($tmpfile, $contents);
        } catch (FilesystemException | UnableToReadFile $exception) {
            throw new \Exception("No se pudo leer el archivo $zip_name");
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpfile) !== true) {
            throw new \Exception("Fallo al abrir <$zip_name> abriendo MR\n");
        }

        if ($zip->locateName($filename) !== false) {
            //XML esta guardado
            $xml = $zip->getFromName($filename);
        } else {
            unlink($tmpfile);
            $zip->close();
            return false;
        }
        unlink($tmpfile);
        $zip->close();
        return $xml;
    }

    /**
     * Desactivar envios futuros
     * Se usa para remover un documento de la cola al haber un fallo permanente
     *
     * @return void
     */
    public function desactivarEnvios()
    {
        $clave = $this->clave;
        $accion_actual = $this->tipo == 'E' ? 1 : 2;
        $sql = "UPDATE fe_cola SET accion=accion + 2
        WHERE clave='$clave' AND accion=$accion_actual";
        $this->container['db']->query($sql);
    }

    /**
     * Aplazar envio de comprobante
     *
     * @return void
     */
    private function aplazarEnvio()
    {
        // Coger intentos actuales
        $clave = $this->clave;
        $accion = $this->tipo == 'E' ? 1 : 2;
        $sql = "SELECT intentos_envio FROM fe_cola WHERE clave='$clave' AND accion=$accion";
        $result = $this->container['db']->query($sql);
        if ($result->num_rows == 0) {
            return;
        }
        $intentos = $result->fetch_row()[0];
        $plazo = 28800; // por defecto 8 hrs
        $plazos = [
            300,   // 5min
            900,   // 15min
            2400,  // 40min
            3600,  // 1hr
            7200,  // 2hrs
            14400  // 4hrs
        ];
        if (isset($plazos[$intentos])) {
            $plazo = $plazos[$intentos];
        }
        $siguiente = (new \DateTime())->getTimestamp() + $plazo;
        $intentos++;
        $accion_nueva = $intentos >= 12 ? $accion + 2 : $accion; // Despues de 12 intentos se desactiva
        $sql = "UPDATE fe_cola SET tiempo_enviar=$siguiente, intentos_envio=$intentos, accion=$accion_nueva
        WHERE clave='$clave' AND accion=$accion";
        $this->container['db']->query($sql);
    }

    /**
     * Guardar mensaje de Hacienda
     *
     * @param string $xml El xml de respuesta de Hacienda
     *
     * @return bool
     */
    public function guardarMensajeHacienda($xml)
    {
        if ($this->id) {
            $clave = $this->clave;
            $filesystem = $this->container['filesystem'];

            //Guardar el archivo
            $storage_path = "{$this->id}/20" . \substr($clave, 7, 2) . \substr($clave, 5, 2) . '/';

            $filename = $this->tipo == 'E' ? 'MH' : 'MHMR';
            $filename .= $clave . '.xml';
            $zip_name = $this->tipo . $clave . '.zip';

            $zip = new \ZipArchive();
            $tmpfile = sys_get_temp_dir() . '/' . $zip_name;

            if ($filesystem->fileExists($storage_path . $zip_name)) {
                //Abrimos el existente y le añadimos los archivos
                try {
                    $contents = $filesystem->read($storage_path . $zip_name);
                    file_put_contents($tmpfile, $contents);
                } catch (FilesystemException | UnableToReadFile $exception) {
                    throw new \Exception("No se pudo abrir el archivo zip para el documento $filename.");
                }
                if ($zip->open($tmpfile) !== true) {
                    throw new \Exception("Fallo al abrir <$zip_name> guardando MR\n");
                }
            } else {
                //Creamos uno nuevo
                if ($zip->open($tmpfile, \ZipArchive::CREATE) !== true) {
                    throw new \Exception("Fallo al abrir <$zip_name> guardando MR\n");
                }
            }

            $zip->addFromString($filename, $xml); //Lo reemplaza si ya existe
            $zip->close();
            $contents = file_get_contents($tmpfile);
            unlink($tmpfile);
            try {
                $filesystem->write($storage_path . $zip_name, $contents);
            } catch (FilesystemException | UnableToWriteFile $exception) {
                throw new \Exception("Fallo al guardar el archivo <$zip_name>\n");
            }

            //Guardar el estado
            $datosXML = Comprobante::analizarXML($xml);
            $mensaje = $datosXML['DetalleMensaje'];
            $estado = $datosXML['Mensaje'] == 1 ? 3 : 4;
            $this->estado = $estado;

            $table = $this->tipo == 'E' ? 'fe_emisiones' : 'fe_recepciones';
            $sql = "UPDATE $table SET estado=?, mensaje=? WHERE clave=?";
            $stmt = $this->container['db']->prepare($sql);
            $stmt->bind_param('iss', $estado, $mensaje, $clave);
            return $stmt->execute();
        } else {
            return false;
        }
    }

    /**
     * Devuelve el detalle del mensaje que respondio Hacienda
     *
     * @return string
     */
    public function cogerDetalleMensaje()
    {
        $table = $this->tipo == 'E' ? 'fe_emisiones' : 'fe_recepciones';
        $sql = "SELECT mensaje FROM $table WHERE clave=? AND id_empresa=?";
        $stmt = $this->container['db']->prepare($sql);
        $stmt->bind_param('si', $this->clave, $this->id);
        $stmt->execute();
        return $stmt->get_result()->fetch_row()[0];
    }

    /**
     * Leer los datos de un comprobante XML
     *
     * @param string $xml El xml a leer
     *
     * @return array El resultado
     */
    public static function analizarXML($xml)
    {

        if ($xml == false) {
            //No enviaron nada, entonces solo daria error
            return [];
        }
        //Eliminar la firma
        $xml = preg_replace("/.ds:Signature[\s\S]*ds:Signature./m", '', $xml);
        $encoding = mb_detect_encoding($xml, 'UTF-8, ISO-8859-1', true);
        if ($encoding != 'UTF-8') {
            //Lo codificamos de ISO-8859-1 a UTF-8
            //para poder leer xmls generados incorrectamente
            $xml = utf8_encode($xml);
        }
        //Coger el elemento root del comprobante
        \preg_match('/\<(\w+)\s+x/', $xml, $results);
        $root = isset($results[1]) ? $results[1] : '';

        //Coger el namespace del comprobante
        \preg_match('/xmlns="([^"]+)"/', $xml, $results);
        global $ns;
        $ns = $results[1];
        global $xmlns;
        $xmlns = '{' . $ns . '}';
        $service = new Service();
        $f_repeatKeyValue = function (\Sabre\Xml\Reader $reader) {
            return XmlReader::repeatKeyValue($reader, $GLOBALS['ns']);
        };
        $f_keyValue = function (\Sabre\Xml\Reader $reader) {
            return \Sabre\Xml\Deserializer\keyValue($reader, $GLOBALS['ns']);
        };
        $f_detalleServicio = function (\Sabre\Xml\Reader $reader) {
            return \Sabre\Xml\Deserializer\repeatingElements($reader, $GLOBALS['xmlns'] . 'LineaDetalle');
        };
        $f_codigoParser = function (\Sabre\Xml\Reader $reader) {
            return XmlReader::codigoParser($reader, $GLOBALS['ns']);
        };

        $elementMap = [
            $xmlns . $root => $f_repeatKeyValue,
            $xmlns . 'Emisor' => $f_keyValue,
            $xmlns . 'Receptor' => $f_keyValue,
            $xmlns . 'Identificacion'  => $f_keyValue,
            $xmlns . 'Ubicacion' => $f_keyValue,
            $xmlns . 'Telefono' => $f_keyValue,
            $xmlns . 'Fax' => $f_keyValue,
            $xmlns . 'Descuento' => $f_keyValue,
            $xmlns . 'Impuesto' => $f_keyValue,
            $xmlns . 'Exoneracion' => $f_keyValue,
            $xmlns . 'ResumenFactura' => $f_keyValue,
            $xmlns . 'DetalleServicio' => $f_detalleServicio,
            $xmlns . 'LineaDetalle' => $f_repeatKeyValue,
            $xmlns . 'OtrosCargos' => $f_repeatKeyValue,
            $xmlns . 'CodigoComercial' => $f_keyValue,
            $xmlns . 'Normativa' => $f_keyValue,
            $xmlns . 'CodigoTipoMoneda' => $f_keyValue,
            $xmlns . 'Otros' => $f_keyValue,
            $xmlns . 'InformacionReferencia' => $f_repeatKeyValue,
            $xmlns . 'OtroContenido' => $f_keyValue
        ];
        if (stripos($xmlns, 'v4.2') > 0) {
            //Comprobante viejo
            $elementMap[$xmlns . 'Codigo'] = $f_codigoParser;
        }
        $service->elementMap = $elementMap;
        return $service->parse($xml);
    }
}
