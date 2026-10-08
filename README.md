# Verificador de documentación por OCR

Las importaciones rechazan fichas que ya tengan lote, incluso cerrado o con otro nombre de archivo. La validación se realiza en el servidor y las importaciones se serializan mediante un bloqueo MySQL por base para evitar duplicados por solicitudes simultáneas. Lote e inscritos se guardan en una transacción. Un reporte sin código de ficha se rechaza. Los duplicados históricos se conservan; esta regla evita nuevas repeticiones y no elimina datos anteriores. El esquema histórico no tiene unicidad de ficha, por lo que escrituras SQL externas no quedan cubiertas por esta validación de importación.

La carga muestra una ventana modal de progreso en verde y blanco. En OCR el porcentaje corresponde a documentos atendidos (un par de caras cuenta como uno), y distingue guardados, errores y lecturas por revisar. La importación de Excel muestra la transferencia y después una espera indeterminada hasta que el servidor responde. No se simulan porcentajes del procesamiento interno de Tesseract. Al finalizar se puede cerrar la ventana y consultar los resultados.

## Datos extraídos del documento

La tabla de verificación incluye «Datos faltantes» y un filtro para mostrar solo filas incompletas. Se pueden completar nombres, apellidos, identificación y fecha en las filas no confirmadas. Guardar conserva las correcciones sin confirmar; la edad se recalcula automáticamente. Los campos vaciados manualmente no recuperan silenciosamente el valor OCR. Las fechas inválidas o futuras se rechazan. Confirmar bloquea la fila y avisa si aún hay datos faltantes.

Para actualizar una base existente, ejecutar una vez `sql/003_datos_manuales.sql`. El informe utiliza los datos corregidos y enumera los faltantes. Recalcular el cruce conserva las filas con ediciones guardadas.

El OCR obtiene identificación, nombres, apellidos y fecha de nacimiento. La edad se calcula en años cumplidos al consultar el resultado, usando la fecha disponible y el día actual en Colombia; no se almacena una edad fija. Nombres y apellidos OCR se muestran separados del nombre de la lista y no cambian la regla de cruce por número.

Para bases existentes sin las columnas de nombres, ejecutar una sola vez `sql/002_nombres_ocr.sql`. Las instalaciones nuevas ya incluyen esas columnas en `sql/schema.sql`. Las lecturas anteriores mantienen nombres vacíos hasta volver a procesar sus imágenes.

La extracción de nombres utiliza etiquetas NOMBRES/APELLIDOS o la línea de nombres de la MRZ. Los campos ilegibles quedan vacíos, sin copiar el nombre del Excel. La fecha frontal requiere una referencia a nacimiento para evitar confundirla con expedición. Tesseract se ejecuta una vez por imagen y se obtiene texto y confianza de esa misma lectura. Los metadatos técnicos se conservan para el diagnóstico.

### Frente y reverso en páginas diferentes

En PDF, las páginas se leen y se asocian automáticamente por sus datos. Para imágenes, seleccionar explícitamente Frente y Reverso en la sección de dos fotos.

Cada cara se lee por separado y se complementan los campos en un único registro, conservando ambas rutas y números de página. Si se detectan números, nombres o fechas contradictorios, se rechaza la unión. Cuando el reverso no tiene identificación legible, la asociación depende de la selección explícita del operador; no es una validación de identidad. Las lecturas antiguas deben volver a subirse en este modo y se debe recalcular el cruce. Las filas con correcciones guardadas o confirmadas se conservan.

Para bases existentes, ejecutar una vez `sql/004_documento_dos_caras.sql` antes de usar la actualización. El esquema de nuevas instalaciones ya contiene estas columnas.

Cruza una lista de inscritos del SENA (reporte `.xls`) contra las cédulas
colombianas escaneadas: lee el número de cada cédula por **OCR en el servidor**
(Tesseract + OpenCV), lo cruza contra la lista **por número normalizado**, y
muestra un **informe editable** donde un operador corrige lo que el OCR haya
leído mal. **Nada se persiste como verificado sin confirmación manual.**

## Stack

- **PHP 8.2**, MVC propio (front controller + Router + Controller base), sin framework.
- **MySQL 8** con PDO (sentencias preparadas siempre).
- **PhpSpreadsheet** para leer el `.xls` (BIFF, lector `Xls` forzado).
- **Frontend** vanilla JS, sin build step. PDF con `pdf.js` (CDN), cámara con `getUserMedia`.
- **OCR** en servidor: `ocr/procesar.py`, invocado por `shell_exec`. Motor
  seleccionable con `OCR_ENGINE`: **RapidOCR** (`rapidocr-onnxruntime`, más
  preciso; por defecto) o **Tesseract** (`pytesseract`, `spa`) como respaldo.
- **Código PDF417**: el número del reverso (cédulas viejas) se lee directo del
  código de barras con `zxing-cpp` (`OCR_BARCODE=1`): exacto y sin OCR.
- **Docker** (PHP 8.2 + Apache) para desplegar en Dokploy.
- Credenciales **solo por variables de entorno** (`.env`, nunca en el repo).

## Estructura

```
public/            # webroot: front controller + assets (css/js)
app/
  Core/            # Database (PDO singleton), Router, Controller, Env, Auth
  Controllers/     # Auth, Lote, Ocr, Verificacion
  Models/          # Lote, Inscrito, DocumentoOcr, Verificacion
  Services/        # ImportadorXls, OcrService, NumeroExtractor, Comparador
  Views/           # lote/, verificacion/, auth/, layouts
ocr/procesar.py    # helper OCR (CLI aislado)
storage/uploads/   # imágenes subidas (fuera del webroot)
sql/schema.sql     # esquema MySQL
Dockerfile · docker-compose.yml · docker/apache-vhost.conf
```

## Cómo levantar con Docker (recomendado)

1. Copia el archivo de entorno y edítalo:
   ```bash
   cp .env.example .env
   ```
   Cambia al menos `DB_PASS` y `MYSQL_ROOT_PASSWORD`.

2. Levanta todo:
   ```bash
   docker compose up --build
   ```
   - La base de datos carga `sql/schema.sql` automáticamente la primera vez.
   - La app queda en `http://localhost:8080` (configurable con `APP_PORT`).

3. Abre `/lotes`: el acceso es directo, sin iniciar sesión.
   Las nuevas importaciones y confirmaciones no se atribuyen a un usuario.

## Cómo correr en local (XAMPP)

Ya que el proyecto vive en `htdocs/OCR`:

1. Crea la base y carga el esquema:
   ```bash
   mysql -u root verificador < sql/schema.sql
   ```
   (crea antes la base: `CREATE DATABASE verificador CHARACTER SET utf8mb4;`)

2. Copia `.env.example` a `.env` y ajusta `DB_*` (en XAMPP suele ser
   `DB_USER=root`, `DB_PASS=` vacío).

3. Instala dependencias PHP:
   ```bash
   composer install
   ```

4. Sirve el `public/` como DocumentRoot. Con el servidor embebido de PHP:
   ```bash
   powershell -ExecutionPolicy Bypass -File .\iniciar-local.ps1
   ```
   El iniciador usa `storage/tmp` para las cargas y registra los avisos de PHP sin
   mezclarlos con las respuestas JSON. Detén el servidor anterior antes de iniciarlo.
   En Apache configura `upload_tmp_dir` con una carpeta escribible y
   `display_errors=Off`, `display_startup_errors=Off`, `log_errors=On` en `php.ini`;
   reinicia Apache. Estos ajustes deben aplicarse al arrancar PHP, no desde el controlador.
   Un aviso de inicio sobre el directorio temporal puede invalidar la respuesta aunque
   el documento se haya guardado. La carga distingue estos resultados no confirmados
   de los errores y no reintenta automáticamente.

   O vía Apache de XAMPP en `http://localhost/OCR/public/` (el `.htaccess`
   incluido maneja el rewrite; requiere `AllowOverride All`).

> El OCR (`ocr/procesar.py`) necesita Python + Tesseract instalados. En Windows
> puedes instalar Tesseract y `pip install opencv-python-headless pytesseract numpy`,
> y apuntar `TESSERACT_BIN` en `.env` al ejecutable. En Docker ya vienen incluidos.

## Flujo de uso

En **Importar y comparar**, selecciona el reporte Excel `.xls` y uno o varios
PDF de la misma ficha, y pulsa **Procesar y comparar**. Se comprueba que los
PDF puedan abrirse, se importa el Excel, se leen las páginas y se ejecuta el
cruce automáticamente. El progreso cuenta las páginas atendidas de todos los
PDF ; las caras se agrupan únicamente dentro del mismo archivo. Usa **Ver
resultados** para revisar coincidencias, diferencias y datos faltantes.

Si la carga se interrumpe después de crear el lote, conserva lo ya guardado y
ofrece acceso a Verificación y a **Continuar carga de documentos**. No vuelvas
a importar el Excel. Si la respuesta de importación no pudo confirmarse, revisa
**Mis lotes** antes de reintentar. La ficha única sigue validándose en el servidor.
La confirmación de las verificaciones continúa siendo manual.

Las caras en páginas diferentes se asocian por los datos leídos. No se requiere
un número par de páginas ni un orden fijo de frente y reverso. También se
conserva la agrupación manual opcional en la carga adicional.

Si una página contiene ambas caras y el serial impreso del reverso contradice
al número del frente, el OCR relee el reverso por separado. Si persiste la
contradicción y hay un único frente identificable por su encabezado y número
impreso, conserva solo sus campos y marca el documento para revisión. No copia
la fecha ni los nombres del reverso, ni permite autocompletar esa lectura.
Dos frentes distintos o una MRZ contradictoria siguen rechazándose.
Regresión: `python -m unittest discover -s tests -p test_conflicto_serial.py`.

En cédulas digitales, la lectura MRZ prioriza el NUIP de la segunda línea
(después de COL, admitiendo el error OCR C0L); el serial de la primera línea
no se usa como identificación. Para unir caras con el mismo NUIP, se admiten
transliteraciones MRZ como Ñ/N y rellenos `<` leídos como K únicamente entre
palabras completas que coincidan con el frente. No se corrigen letras internas
ni números por semejanza. Pruebas: `tests/test_nuip_mrz.py` y `tests/agrupador_pdf.php`.

Si un frente con nombres y un reverso con serial impreso son consecutivos en
la misma carga y sus números solo difieren en una posición, el sistema puede
proponer el par para revisión. Exige un único candidato por cada cara y no
modifica asociaciones manuales ni confirmadas. Conserva ambos números en la
evidencia, mantiene los campos del frente y no copia la fecha del reverso.
La propuesta lleva `[REVISION OCR]` y `[PAR PROPUESTO OCR]`: no constituye una
coincidencia ni una confirmación de identidad.

La agrupación tolera espacios perdidos dentro de nombres y apellidos cuando
las letras y el número coinciden. Las correcciones de formato no bloquean una
unión posterior; los datos inferidos y las revisiones manuales conservan su
protección. En cédulas digitales, NUIP determina la dirección de las etiquetas:
si falta «Apellidos», solo se recupera el texto situado entre NUIP y «Nombres».
La fecha admite G.S. intercalado por el orden de columnas, sin saltar a expedición.

Para validar el flujo completo con los originales de un lote existente:
`php tests/regresion_lote_ocr.php ID_LOTE`. Relee cada imagen original, crea un
lote temporal, agrupa, compara dos veces y verifica la estabilidad de las
asociaciones. El lote temporal se elimina al terminar; el lote original y sus
imágenes no se modifican. El informe queda en `storage/tmp/regresion_ocr_loteID.json`
y contiene datos personales. Las imágenes no asociadas provocan un fallo de prueba;
las discrepancias de datos se reportan y no se confirman automáticamente.

Pruebas del flujo de carga: `node tests/carga_conjunta.cjs`,
`node tests/uploader_first_result.cjs` y `node tests/pdf_review_cache.cjs`.

Al abrir Verificación se actualiza la asociación automáticamente: primero
por identificación exacta, después por nombres y apellidos completos con
coincidencia única. Si se asocia por nombre, el número queda por revisar,
sin copiar el número del Excel como si lo hubiera leído el OCR. Las
aproximaciones numéricas solo se aceptan sin empates ni competencia entre
personas. Las asociaciones manuales y confirmaciones se conservan. Si faltan
tanto el número como el nombre completo, la imagen queda en «sin asociar».

Las sesiones se guardan en `storage/sessions`, fuera del directorio público,
con la cookie propia `OCRSESSID`. PHP crea el directorio si no existe y su
cuenta de ejecución necesita permisos de escritura allí. No utiliza las
sesiones compartidas de XAMPP. Después de cambiar el almacenamiento, recarga
Verificación para obtener el nuevo token antes de asociar imágenes.

La lectura por zonas utiliza las posiciones de las etiquetas `NUMERO`/`NUIP`,
`NOMBRES`/`APELLIDOS` y `NACIMIENTO` reconocidas por Tesseract. Lee esas regiones
en escala de grises con margen y ampliación limitada, y aplica cada resultado
solo a sus campos correspondientes. Las evidencias quedan en `texto_crudo` con
marcadores `[ZONA ...]`. Si difiere de la lectura general, el campo queda pendiente
y se emite un aviso. Si las etiquetas no se reconocen, se mantiene la lectura
general: no se asignan campos basándose únicamente en posiciones fijas.

Antes del OCR se buscan hasta dos regiones rectangulares de cédula para reducir
los márgenes. La lectura principal conserva los tonos en escala de grises:
no se aplica umbral Otsu a todas las imágenes, porque puede borrar texto sobre
el fondo de seguridad. Si la unión de dos páginas falla, se reintenta una vez
con el procesamiento binarizado anterior, manteniendo la comprobación de
identificación, fecha y nombres entre caras. Las identificaciones impresas
de más de diez dígitos se descartan, sin recortar dígitos para forzar un número.

Para evaluar las imágenes ya cargadas de un lote sin modificar la BD:
`php ocr/evaluar_lote.php ID_LOTE`. El resultado privado queda en
`storage/tmp/evaluacion-lote-ID_LOTE.json`; contiene datos personales.

El recorte es conservador: si quedan
contenidos importantes fuera de las regiones o no hay límites claros, se usa la
imagen completa. Las imágenes originales no se modifican. Si las lecturas de los
recortes arrojan identificaciones distintas, se devuelve un aviso para revisión
en lugar de mezclar sus datos. El cambio se aplica a nuevas lecturas, no modifica
registros existentes ni garantiza recuperar todos los campos.

Las filas confirmadas ofrecen **Editar** para corregir o completar los datos.
Esta acción conserva los valores, retira la confirmación anterior y devuelve el
lote a `en_proceso`, incluso si estaba cerrado. Después de editar, pulsa **Guardar**
y **Confirmar** nuevamente. La lectura OCR original no se modifica.

1. **Importar lista** (`/lotes/nuevo`): sube el `.xls` del SENA. Se detecta la
   fila de encabezados (la que contiene *Identificación*), se normaliza la
   columna de identificación a solo dígitos y se guarda el tipo (CC/TI/CE...).
2. **Subir documentos** (`/lotes/{id}/documentos`): tres adaptadores que terminan
   subiendo una imagen al mismo endpoint `POST /ocr/procesar`:
   - **PDF multipágina** → cada página se rasteriza con `pdf.js` y se sube.
   - **Fotos sueltas** → `input file multiple`.
   - **Cámara en vivo** → `getUserMedia`, captura de frame.
3. **Verificación** (`/lotes/{id}/verificacion`): tabla editable con el cruce.
   El operador corrige a mano y **confirma fila por fila**. El lote solo se
   **cierra** cuando **todas** las filas están confirmadas.
4. **Informe** (`/lotes/{id}/informe`): resumen y detalle (imprimible a PDF).

## Zonas de lectura del número (cédula colombiana)

`ocr/procesar.py` intenta la zona más confiable disponible y cae a la siguiente:

0. **Código PDF417 del reverso** (`zxing-cpp`, cédulas viejas): el payload de la
   Registraduría empieza con el número a 10 dígitos. Se lee **directo del código,
   sin OCR** — exacto y muy rápido (~0.5 s). Si aparece, se usa (`zona=pdf417`,
   confianza 99) y no se ejecuta el motor OCR. El QR de la cédula digital nueva
   va comprimido/firmado y no se decodifica.
1. **MRZ** (cédulas nuevas): `COL<número><`
2. **Código de barras** (amarillas viejas, vía OCR): `-[MF]-0*<número>-`
3. **Número frontal**: `1.234.567.890`

El motor de OCR (para 1–3 y para nombres/fecha) es RapidOCR o Tesseract según
`OCR_ENGINE`. RapidOCR es más preciso pero su inferencia en CPU es más lenta que
Tesseract; el atajo del PDF417 evita el OCR por completo cuando el código existe.

## Estados de verificación

- `ok` — hay un documento cuyo número leído coincide con el del inscrito.
- `corregido` — el operador editó el número a mano y ahora coincide (`corregido_manual = 1`).
- `no_coincide` — hay un documento emparejado tentativamente (número cercano) pero no cuadra.
- `falta_documento` — no hay ningún documento para ese inscrito.

Los **documentos sobrantes** (número leído que no está en la lista) se listan aparte.

## Seguridad y decisiones

- **PDO preparado** en todas las consultas.
- Credenciales solo por entorno; `.env` está en `.gitignore`.
- Imágenes en `storage/uploads` **fuera del webroot** (Apache además las bloquea).
  Se **conservan tras confirmar** para auditoría.
  <!-- TODO: política de retención/borrado de imágenes. -->
- Auth simple de un solo operador; columnas `creado_por` / `verificado_por` y
  tabla `usuarios` listas para escalar a roles.

### Cambiar la clave del operador

```bash
php -r "echo password_hash('TU_NUEVA_CLAVE', PASSWORD_DEFAULT), PHP_EOL;"
```
y actualiza `usuarios.password_hash` con ese valor.

## Endpoint OCR (contrato)

`POST /ocr/procesar` (multipart): `imagen`, `lote_id`, `origen` (`pdf|foto|camara`), `pagina?`

```json
{ "ok": true, "documento_id": 12, "numero": "1127070363", "zona": "mrz", "confianza": 92.4, "pagina": null }
```

## Notas de desarrollo / pruebas

### Tiempo de respuesta del OCR

Cada solicitud OCR tiene un presupuesto de 25 segundos en el servidor, compartido
por frente y reverso. El proceso Python se termina si supera
ese presupuesto; las imágenes se conservan como documentos para revisión manual.
El navegador espera como máximo 30 segundos por solicitud (incluye la subida).
Si vence esa espera, hay que comprobar Verificación antes de reenviar: el servidor
puede haber guardado el documento aunque la respuesta no haya llegado.

Las dos caras se unen en memoria (frente arriba, reverso abajo, margen blanco) y
se envían en una sola inferencia al modelo. El texto resultante se distribuye por
posición entre ambas caras, manteniendo la validación de identidad al combinarlas.
Esta ruta no repite OCR por zonas ni hace reintentos binarizados: los campos que
no se reconozcan quedan para revisión. Se conservan las dos imágenes originales
y la lectura PDF417 sobre ellas. La carga de una sola imagen mantiene su ruta habitual.
OpenCV limita sus hilos según
`OCR_THREADS`, y las páginas PDF se renderizan hasta 1600 píxeles en su lado mayor.
El detector de RapidOCR limita su entrada a `RAPIDOCR_DET_SIDE=960` píxeles,
sin ampliar recortes pequeños. Este límite se aplica en el preprocesador del
detector: la versión 1.4.4 ignora `det_limit_side_len` al invocar el motor.
El reconocimiento sigue recibiendo los recortes de la imagen original. La
dependencia está fijada a la versión probada y el test de resolución comprueba
que el límite sea efectivo. La búsqueda de códigos se restringe a PDF417.
El reconocedor usa `OCR_REC_BATCH=1` (una línea por cálculo) para evitar rellenar
seis líneas al ancho de la más larga. Los modelos reutilizan buffers de memoria
y sus hilos dejan de consumir CPU cuando esperan trabajo (`allow_spinning=0`;
[documentación de ONNX Runtime](https://onnxruntime.ai/docs/performance/tune-performance/threading.html)).
Se mantiene el modelo original: las alternativas latinas probadas no se activaron.
En la validación por HTTP con Apache, subida de imágenes e inserción revertida
en MySQL, el PDF de 42 páginas agrupado en 30 documentos tardó 121,97 segundos;
el primer resultado llegó a los 5,98 segundos. Es una medición local, no una
garantía de tiempo. Ocho muestras conservaron los campos extraídos; dos apellidos
recuperaron espacios faltantes. El endpoint temporal de diagnóstico se eliminó.
El procesamiento usa dos motores Python persistentes y dos solicitudes simultáneas.
El primer documento del PDF se procesa solo para obtener el primer resultado
pronto; después se utilizan ambos motores. La barra cuenta documentos terminados,
sin porcentajes simulados. Prueba de prioridad: `node tests/uploader_first_result.cjs`.
`POST /ocr/preparar` los inicia durante la preparación de la vista previa. Cada
motor carga el modelo una vez y atiende lecturas sucesivas; se libera tras cinco
minutos inactivo. PHP reserva un motor mediante un bloqueo de archivo y se comunica
por un socket exclusivo en 127.0.0.1 con token aleatorio. Solo se aceptan imágenes
de `storage/uploads`. El estado local en `storage/tmp/ocr-workers` bloquea acceso
HTTP mediante `.htaccess`. Al cambiar código o configuración se usan motores nuevos.
Un watchdog termina una lectura bloqueada al vencer el plazo y el siguiente pedido
reinicia ese motor. No hay que iniciar un servicio manualmente.

Las páginas donde se detectan dos caras también se leen en una sola inferencia,
manteniendo la reconciliación de los datos por cara. En cuatro páginas comparadas
se conservaron los cuatro campos principales; esta muestra no garantiza exactitud
para todas las páginas. El límite de espera es por
documento, no por lote: el total depende de la cantidad, la red y el equipo.
La preparación local de un PDF ocurre antes de iniciar el tiempo de la solicitud.
PDF.js y su worker se sirven desde `public/assets/vendor/pdfjs` (versión 3.11.174,
con aviso de licencia incluido), sin depender del CDN. La vista previa genera
también las imágenes JPEG que se enviarán al OCR y retiene hasta 64 MiB de ellas.
Al procesar se reutilizan las imágenes; las que exceden la caché se renderizan
desde el documento ya abierto. Al cambiar de archivo o terminar se libera la
caché y el documento. Esto elimina la reapertura del PDF y el renderizado
duplicado de las páginas almacenadas, pero no garantiza 30 segundos por lote.
El contador muestra tiempo real transcurrido y porcentaje de documentos atendidos.
Prueba de caché y recuperación de errores: `node tests/pdf_review_cache.cjs`.

Prueba del límite real de ejecución: `php tests/ocr_process.php`.
Prueba del reinicio persistente: `php tests/ocr_worker_recovery.php ruta-a-imagen-en-uploads`.
Validación de rutas: `python tests/test_worker.py`.
Las respuestas incluyen `tiempos_ms` (preparación, motor y guardado). En el navegador,
`window.OCR_TIMINGS` conserva tiempos de envío, respuesta y servidor, sin datos personales.
`tiempos_ms.detalle_motor` añade espera por un motor, arranque e inferencias;
cada inferencia informa detección, clasificación y reconocimiento en milisegundos.
Pruebas de lectura conjunta: `python tests/test_lectura_conjunta.py` y
`php tests/ocr_caras.php`. Para comparar tiempos y coincidencia de campos sin
imprimir datos personales: `python tests/benchmark_caras.py frente.jpg reverso.jpg`.
El argumento de diagnóstico `--caras-separadas` de `ocr/procesar.py` permite
comparar con la ruta anterior; la aplicación utiliza la lectura conjunta.

El parser de `.xls` (`ImportadorXls::parsear`) está desacoplado de la base de
datos para poder probarse en aislamiento. La lógica de cruce vive en
`Comparador` y se validó de extremo a extremo (importar → OCR → cruce →
confirmar → cerrar) contra una base MySQL real.

## Lectura por zonas

La lectura progresiva de RapidOCR está habilitada con `OCR_ZONES=1` (valor
predeterminado). Detecta las líneas una vez, reconoce etiquetas y luego sus
zonas cercanas de número, nombres, apellidos y nacimiento. Conserva las líneas
largas que podrían ser MRZ o seriales de identificación. Si faltan campos o no
reconoce las etiquetas, completa las líneas pendientes sin repetir las ya leídas.
No usa coordenadas fijas de una plantilla. El texto crudo puede omitir líneas
ajenas a estas zonas; los originales se conservan para revisión.

`OCR_ZONES=0` restaura la lectura completa para comparaciones. Cambiar esta
opción o `ocr/zonas.py` invalida la versión de los motores persistentes.
Las métricas de cada inferencia incluyen `zonas.lineas_detectadas`,
`lineas_reconocidas` y `lectura_completa`. Validación: `python tests/test_zonas.py`.
La ganancia depende de la imagen y de cuántas lecturas completas de respaldo
necesite; esta opción no garantiza 30 segundos por lote.

Comparación local del motor (2026-09-22, 42 páginas agrupadas en 30 documentos):
sin diferencias en número, nombres, apellidos, nacimiento ni avisos frente a la
lectura completa. Diez documentos omitieron líneas; veinte necesitaron lectura
completa. Suma de tiempos con modelos calientes: 44,18 s completa y 43,00 s por
zonas. Es una mejora pequeña, sensible a variación de CPU; no es una medición
del flujo HTTP ni del tiempo total que ve el usuario en el navegador.

## Caché de resultados OCR

`OCR_CACHE=1` (predeterminado) reutiliza durante siete días la lectura original
de imágenes con contenido idéntico, aunque cambie el nombre del archivo. La
clave SHA-256 incluye ambas caras en orden, binarización, idioma, configuración,
código OCR y año actual. Un cambio de agrupación o configuración produce una
lectura nueva. Al reemplazar modelos o actualizar paquetes Python instalados,
incrementar `OCR_CACHE_VERSION` para invalidar lecturas previas.

Los resultados se guardan en `storage/tmp/ocr-cache`, protegido con `.htaccess`.
Las entradas vencidas se eliminan al consultarlas y mediante una limpieza
acotada en nuevas lecturas. Las solicitudes simultáneas de la misma imagen
comparten un cálculo. Errores, resultados vacíos y conflictos entre caras no se
guardan. Se mantienen las revisiones de campos faltantes, se recalcula la edad
y no se modifican correcciones manuales ni documentos previamente guardados.

La primera carga posterior a activar esta función llena la caché; no recupera
automáticamente lecturas históricas. Una imagen recomprimida o editada puede
tener otra clave. El ahorro corresponde a cargas repetidas; no acelera la
primera inferencia. `tiempos_ms.detalle_motor.cache.acierto` indica reutilización;
un acierto tiene `inferencia: []`. Usar `OCR_CACHE=0` para medir el motor sin caché.

Pruebas: `php tests/ocr_cache.php`, `php tests/ocr_cache_concurrent.php` y
`php tests/ocr_cache_integration.php imagen-en-uploads [reverso-en-uploads]`.

## Medición de eficiencia

`tests/benchmark_worker.php` desactiva la caché e informa espera, arranque y
tiempos de detección, clasificación y reconocimiento, además de campos leídos.
No imprime datos personales. La preparación de variantes PDF417 es diferida:
se crea cada imagen únicamente si la anterior no permite leer el código. Las
variantes y su orden son iguales; se evita conservar las siete copias a la vez.
Prueba de equivalencia: `python tests/test_barcode_efficiency.py`.

Medición del 2026-09-22: 30 documentos, dos motores, sin caché, por HTTP sobre
imágenes ya almacenadas: 25,99 s, primer resultado 1,15 s; 21 documentos con los
cuatro campos completos. No incluye subida, preparación del PDF ni inserción
en la base de datos. No es una garantía del tiempo total del navegador. La
variación entre ejecuciones impide atribuir esa cifra al ahorro de copias.
Se descartaron reducir los hilos (más lento) y reducir el relleno de entrada
del reconocedor (cambió un apellido en la muestra).

## Fiabilidad del cruce con Excel

El número exacto ya no basta para un `ok` automático. Se exige concordancia del
nombre completo (normalizando tildes, separadores y orden de palabras), confianza
OCR presente de al menos 55 y ausencia de avisos. El umbral es una heurística,
no un porcentaje validado de acierto. Datos incompletos o nombres discrepantes
quedan como `no_coincide`, mostrado como «Revisar coincidencia».

Un número a uno o dos caracteres de distancia solo propone una asociación si
también coinciden los nombres o difiere una sola palabra larga por un carácter.
Las propuestas nunca corrigen números ni se confirman automáticamente. Se
rechazan empates y homónimos ambiguos; los escaneos repetidos con igual número
y nombre no bloquean una propuesta. Las confirmaciones y correcciones manuales
se conservan. La fecha no se valida contra Excel con estas reglas.

Pruebas: `php tests/comparador_identidad.php`. Auditoría sin cambios en BD ni
impresión de datos personales: `php tests/auditar_cruce.php ID_LOTE`.
Estos controles reducen asociaciones sin respaldo; la exactitud real del OCR
requiere comparar los campos con las imágenes revisadas por una persona.

## Conciliación previa a la verificación

`Conciliador::preparar` compara Excel y OCR antes de renderizar la tabla. Los
ajustes propuestos se precargan en campos editables y llevan una explicación;
no cambian originales ni elevan una coincidencia dudosa a OK. Se guardan con
las acciones existentes de Guardar/Confirmar. No se reescribe el PDF ni el Excel.

Con identificación exacta admite ajustes de formato o una letra en una palabra
del nombre completo, conservando la separación OCR entre nombres y apellidos.
Con nombres exactos y un dígito diferente puede proponer el número del Excel si
el OCR frontal tiene baja confianza. Un PDF417 de confianza alta señala una
posible corrección de Excel, sin asumir que es certeza. MRZ o números impresos
discordantes no se corrigen por preferencia arbitraria. Se omiten filas manuales,
confirmadas, ambiguas, con alertas o sin nombres completos; no se inventa fecha.

Prueba: `php tests/conciliador.php`.

## Autocompletar campos vacíos automáticamente

Antes de recalcular la verificación, `Autocompletador` guarda faltantes en la
lista importada y en los registros de documentos, dentro de una transacción.
Exige cédula exacta y única, lectura sin alertas y confianza mínima 55. Para
copiar un nombre completo desde PDF hacia una lista vacía exige confianza 80,
ambos campos leídos y ausencia de nombres contradictorios en otros escaneos.
Se respetan filas manuales/confirmadas y lotes cerrados. No reemplaza nombres
existentes. `(sin nombre)` cuenta como vacío.

También corrige identificaciones por nombre completo exacto y único: un número
OCR vacío o que difiera en un carácter puede tomarse del Excel si es una lectura
frontal de confianza menor de 55. En sentido inverso, un PDF417 decodificado,
con marcador de verificación y confianza mínima 90, permite corregir un carácter
del número de la lista. Se rechazan homónimos, números ya asignados a otra persona,
lecturas previamente completadas y escaneos contradictorios. No se trata una
confianza alta ni un serial impreso como prueba de código decodificado. Los
valores anteriores quedan en la auditoría; copiar no confirma identidad.
Prueba específica: `php tests/autocorreccion_numero.php`.

El Excel SENA guarda solo nombre completo. Si al OCR le falta un componente,
se obtiene quitando del nombre completo las palabras del componente conocido,
si esa correspondencia es única. Si faltan ambos, se guarda `nombre_completo`
sin inventar una separación; otro escaneo completo concordante permite recuperar
ambos campos separados. La interfaz muestra el nombre completo recuperado.

`autocompletados` conserva destino, fuente, valores anteriores/nuevos y fecha.
Copiar datos no cuenta como confirmación independiente de identidad. Las copias
desde Excel se señalan en el texto OCR, y los nombres de lista recuperados del
PDF conservan su procedencia en la verificación. Los archivos PDF/XLS originales
no se reescriben: los cambios se guardan en la base de datos de la aplicación.

Instalaciones existentes: `php tests/migrar_autocompletado.php` aplica la
migración 005 de forma repetible. Nuevas instalaciones usan `sql/schema.sql`.
Pruebas: `php tests/autocompletador.php` y `php tests/autocompletador_db.php`
(esta última crea un lote de prueba y lo elimina al terminar).
`php tests/autocompletar_lote.php ID` muestra cantidades sin datos personales;
con `--aplicar` completa los faltantes elegibles y registra cada cambio.

## Pendiente (opcional)

Corrección del cruce por seriales PDF417 (2026-09-23): el prefijo decodificado ya
no reemplaza un número OCR diferente ni permite omitir nombres y fecha. En
lecturas conjuntas solo se admite si coincide con la identificación extraída.
La fuente `pdf417` se persiste como `codigo_barras`, compatible con el ENUM SQL.
La recuperación admite cédula digital con NUIP, apellidos unidos y Ñ leída como
N cuando el número frontal exacto y las líneas del nombre respaldan el cruce.
Puede reparar registros antiguos con un serial equivocado usando número frontal
impreso, nombre y Excel concordantes; el registro conserva auditoría y revisión.

Antes de mostrar verificación también se recuperan campos presentes en el texto
OCR que el parser inicial dejó vacíos. `RecuperadorCampos` usa el nombre completo
del Excel y dos líneas consecutivas de apellidos/nombres en el formato antiguo
identificado por «IDENTIFICACION PERSONAL». Admite espacios perdidos sin elegir
la separación por cantidad de palabras; rechaza separaciones ambiguas y formatos
desconocidos. Las anotaciones generadas por la aplicación no sirven de evidencia.
La fecha se recupera junto a una etiqueta de nacimiento con hasta una letra
dañada, validando calendario y rechazando fechas contradictorias. No usa fecha
de expedición. Los cambios se guardan en la auditoría; no requieren nueva inferencia.
Prueba: `php tests/recuperador_campos.php`.

- Exportar el informe a `.xlsx` con PhpSpreadsheet.
- Política de retención/borrado de imágenes en `storage/uploads`.
- Roles y multiusuario.
