# Qhatuq: agente de ventas con IA para WordPress

*Qhatuq* significa "el que vende" en quechua. Es un plugin que agrega un chat a su sitio WordPress. El chat atiende a los visitantes como un asesor comercial:

- **Conoce su oferta.** Usa un catálogo de productos y servicios que usted edita desde wp-admin.
- **Sabe lo que no ofrecen.** Para cada caso usted define qué hacer: decir que no con amabilidad (opción por defecto), ofrecer una alternativa o recomendar a un socio de negocio.
- **Respeta prohibiciones configurables.** Por ejemplo: no ofrecer descuentos, no comprometer plazos, no hablar de la competencia.
- **No da precios.** Si el cliente insiste y el producto tiene un precio referencial autorizado, responde "el precio empieza alrededor de…" y lo remite a un representante.
- **Tres temas visuales** para que cada sitio luzca distinto:
  - **Clásico:** cabecera blanca.
  - **Oscuro:** pensado para sitios de diseño oscuro.
  - **Vibrante:** degradado del color de marca y botón tipo píldora con texto.

  Se eligen en Ajustes → Apariencia; el enlace "Vista previa" muestra el tema en el sitio solo al administrador, sin guardar el cambio.
- **Ícono del botón del chat**, a elegir:
  - el globo de conversación;
  - la foto del agente, con indicador de "en línea" (o su inicial si no hay foto).
- **Saludo según la página.** Cada producto o servicio puede tener páginas asociadas (elegidas de la lista o por partes de la dirección, como `/telefonia-ip`) con su propio saludo y sugerencias rápidas. En esas páginas el chat se abre con ese saludo y el agente sabe qué servicio está mirando el visitante.
- **Verifica productos fuera del catálogo** (con Claude o Gemini). Si piden una licencia, suscripción o equipo que no está en el catálogo ni en "Lo que no ofrecemos", el agente busca en la web si se vende abiertamente en EE. UU. o Ecuador:
  - **Si se vende:** lo trata como un producto más y pasa a cotizar.
  - **Si no es concluyente:** con sus propias palabras ofrece que alguien del equipo lo confirme.

  Nunca menciona precios encontrados ni envía al cliente a otras tiendas. Las búsquedas quedan registradas en la conversación y el resultado en el lead.
- **Registra leads.** Pide nombre, teléfono, correo, empresa y necesidad (productos y cantidades), y clasifica cada lead como caliente, tibio o frío.
- **Avisa por correo** al equipo comercial cuando un lead tiene datos de contacto y una necesidad.
- **Resume cada conversación por correo.** Cuando el visitante deja de escribir (30 minutos por defecto), la IA prepara un resumen para los comerciales: qué buscaba, qué datos dejó, qué quedó pendiente y un siguiente paso sugerido. Si alguien mostró interés concreto y se fue sin dejar contacto, el correo llega marcado con ⚠. Las conversaciones sin interés comercial no se envían (es configurable). Los lunes a las 8:00 llega un resumen semanal.
- **WhatsApp con un clic.** Si el lead dejó un celular, aparece un ícono que abre el chat de WhatsApp en una pestaña nueva, con un saludo ya escrito y editable. También va como enlace en los correos de aviso y de resumen. Los celulares ecuatorianos (09…) se convierten a +593 automáticamente; los fijos no muestran el ícono. El código de país y el mensaje se configuran en Ajustes.
- **Eliminar leads y conversaciones**, uno por uno o en lote, siempre con confirmación. Al borrar un lead puede borrar también su conversación (por ejemplo, ante un pedido de eliminación de datos personales), y viceversa.
- **Guarda las conversaciones** en un panel con las conversaciones completas, los leads con su estado (nuevo, contactado, cotizado, ganado, perdido), notas internas y exportación a CSV.
- Funciona con **Claude (Anthropic)** o con **Gemini (Google)**.
- **Interfaz moderna y aislada del tema:** el chat se dibuja en un Shadow DOM, así que BeTheme u otros temas no alteran su aspecto. Tiene foto del agente (se sube desde la Biblioteca de medios), sugerencias rápidas, burbuja de invitación opcional, contraste automático según el color de marca y pantalla completa en móviles.

## Ajustes

La pantalla **Agente de ventas → Ajustes** está organizada en pestañas:
- **General:** estado, proveedor, API keys, modelos y verificación web.
- **Agente:** empresa, personalidad y reglas.
- **Apariencia:** tema, color, foto, ícono y textos, con una **vista previa en vivo** del chat abierto y cerrado que se actualiza antes de guardar.
- **Avisos:** correos, resúmenes y WhatsApp.
- **Privacidad y límites.**
- **Herramientas:** prueba de conexión e instrucciones del agente.

El botón "Guardar ajustes" guarda todas las pestañas a la vez.

## Varios sitios: exportar e importar

En **Agente de ventas → Exportar / Importar** puede descargar la configuración de un sitio y cargarla en otro. Incluye los ajustes, los productos y servicios, "Lo que no ofrecemos" y, si quiere, la foto del agente. **Las API keys nunca se exportan.**

Al importar se muestra primero una vista previa:
- **Ajustes:** elige qué grupos traer. Los que suelen cambiar entre sitios, como empresa, privacidad y apariencia, vienen desmarcados.
- **Catálogo y "Lo que no ofrecemos":** elige entre combinar (agrega y actualiza por nombre, sin borrar), reemplazar (deja exactamente lo del archivo y envía el resto a la papelera, de donde se puede recuperar) o no importar.

## Requisitos

- WordPress 6.5 o superior (probado con 7.x) y PHP 8.1 o superior.
- Una API key de [Claude](https://platform.claude.com) o de [Gemini](https://aistudio.google.com).

## Instalación

1. Descargue el ZIP del plugin. Lo genera GitHub Actions en cada push: pestaña **Actions** → la ejecución más reciente → artefacto **qhatuq-plugin**. El archivo descargado (`qhatuq-plugin.zip`) es el plugin listo para subir: **no lo descomprima**.
   También se adjunta como `qhatuq.zip` a cada release `v*`. Si prefiere generarlo usted mismo, ejecute `bin/build-zip.sh` (queda en `dist/qhatuq.zip`; requiere `composer` y `zip`).
   No use el botón "Download ZIP" del repositorio: ese archivo no incluye las dependencias (`vendor/`).
2. En WordPress vaya a **Plugins → Añadir nuevo → Subir plugin**, suba el ZIP y actívelo.
3. Vaya a **Agente de ventas → Ajustes**:
   - Elija el proveedor y pegue la API key.
   - Complete la descripción de la empresa, el nombre del agente, el trato (usted o tú) y los correos para avisos.
   - Pulse **Probar conexión**.
4. Cargue el catálogo en **Agente de ventas → Productos y servicios** y en **Lo que no ofrecemos**.
5. Al final de la pantalla de Ajustes puede revisar la **vista previa de las instrucciones**: muestra exactamente lo que recibe el agente.

> **Recomendado:** guarde las claves en `wp-config.php` en lugar de la base de datos:
>
> ```php
> define( 'QHATUQ_CLAUDE_API_KEY', 'sk-ant-...' );
> define( 'QHATUQ_GEMINI_API_KEY', '...' );
> ```

> **Instalación desde el repositorio** (sin ZIP): copie la carpeta en `wp-content/plugins/qhatuq` y ejecute `composer install --no-dev` dentro de ella. La carpeta `vendor/` no se versiona.

## Modelos y costos

| Proveedor | Modelo | Precio por millón de tokens (entrada / salida) |
|---|---|---|
| Claude | **Claude Opus 5.5** (por defecto) | US$4 / US$20 |
| Claude | Claude Sonnet 5.5 | US$2 / US$10 |
| Claude | Claude Haiku 4.5 | US$1 / US$5 |
| Gemini | Configurable (por defecto `gemini-3-flash-preview`) | Según la tarifa de Google |

- Una conversación de unos 10 mensajes con Claude Opus 5.5 cuesta aproximadamente entre US$0.10 y 0.20. Con el tráfico previsto (unas decenas de conversaciones al mes), el gasto mensual es de pocos dólares.
- Las instrucciones y el catálogo se envían con caché de prompts, así que repetirlos en cada mensaje cuesta una fracción del precio normal.
- Cada conversación muestra en el panel los tokens que consumió.

> **Búsqueda web:** se activa en Ajustes → "Productos fuera del catálogo" y viene activada por defecto.
> - **Con Claude:** usa la búsqueda web de Anthropic dentro de la misma respuesta. Cuesta US$10 por cada 1.000 búsquedas, con un máximo de 3 por mensaje.
> - **Con Gemini:** el agente usa la herramienta `verificar_producto`, que hace una consulta aparte con la búsqueda de Google y devuelve un veredicto (disponible, no concluyente u otra región). Se factura según la tarifa de Google y es algo más lenta, porque son dos peticiones.
>
> En los dos casos se aplican las mismas reglas. Si la API de Claude rechaza la búsqueda, revise en platform.claude.com que la búsqueda web esté permitida para su organización. Mientras tanto, el error queda registrado en la conversación.

## Cómo funciona

```
Navegador (widget) ──► /wp-json/qhatuq/v1/chat ──► Qhatuq_Agent ──► Claude o Gemini
                                                        │
                                       herramientas: registrar_lead,
                                       consultar_precio_referencial
                                                        │
                                  tablas wp_qhatuq_conversations / _messages / _leads
```

- **Instrucciones congeladas por conversación.** Se generan a partir de los ajustes y el catálogo al iniciar cada conversación. Si cambia el catálogo, el cambio se aplica a las conversaciones nuevas.
- **Historial sin alteraciones.** El historial se guarda en el formato nativo de cada proveedor y se reenvía tal cual, solo agregando mensajes nuevos. Así el modelo conserva su razonamiento previo y se aprovecha el caché.
- **Los precios no están en las instrucciones.** El agente solo puede consultarlos con una herramienta, y solo los de productos marcados como "puede mencionarse".
- **Sesión del chat.** El chat no usa cookies de sesión: cada conversación tiene un token secreto guardado en el navegador del visitante. Funciona aunque la página esté en caché.
- **Límites contra el abuso** (configurables): mensajes por conversación, mensajes por hora por IP y largo máximo de cada mensaje.
- **Errores y rechazos.** Si la API falla o el modelo rechaza un mensaje, el visitante recibe una respuesta amable y el error queda registrado en la conversación.
- **Retención de datos.** Las conversaciones se borran pasado el plazo configurado (365 días por defecto). Los leads se conservan.

## Notas para los sitios de Hanaq

- **Tareas programadas (importante para los resúmenes):** WordPress ejecuta sus tareas programadas (WP-Cron) solo cuando alguien visita el sitio. Con poco tráfico, los resúmenes pueden demorar horas. En Plesk, vaya a **Herramientas y configuración → Tareas programadas** (o "Tareas programadas" del dominio) y cree una tarea cada 5 minutos que abra la URL `https://SU-SITIO/wp-cron.php?doing_wp_cron`. Opcionalmente, agregue `define( 'DISABLE_WP_CRON', true );` en `wp-config.php` para que solo se ejecute con esa tarea.
- **Correo:** para que los avisos no caigan en spam, configure un plugin SMTP (por ejemplo, WP Mail SMTP) con una cuenta real de la empresa.

- **Wordfence:** el chat usa la REST API de WordPress. Si activa opciones que bloquean la REST API para visitantes anónimos, permita la ruta `/wp-json/qhatuq/v1/`.
- **Caché del sitio** (BeTheme, Plesk o un plugin de caché): no hace falta excluir nada. Las respuestas del chat no se guardan en caché porque son peticiones POST.
- **Privacidad:** según la Ley Orgánica de Protección de Datos Personales de Ecuador, indique en la política de privacidad que las conversaciones del chat se procesan con un proveedor de IA externo. El aviso que se muestra en el chat se edita en Ajustes.
- **Varios sitios:** instale el plugin en cada sitio. Cada uno tiene su propio catálogo, ajustes y leads.

## Personalización para desarrolladores

- Filtro `qhatuq_show_widget` (bool): oculta el chat en ciertas páginas.
  ```php
  add_filter( 'qhatuq_show_widget', fn() => ! is_page( 'contacto' ) );
  ```
- Filtro `qhatuq_gemini_endpoint`: cambia la URL de la API de Gemini (útil para pruebas).
