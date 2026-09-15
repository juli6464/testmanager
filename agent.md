# Prompt Maestro de Desarrollo: Plugin `local_testmanager` (Gestión de Tests)

Actúa como un desarrollador senior experto en Moodle y arquitectura de plugins. Debes seguir estrictamente los Moodle Coding Standards, XMLDB, la Privacy API, y las buenas prácticas de seguridad de Moodle (verificaciones obligatorias de capacidades con `require_capability` y validación de tokens `sesskey`).

## Contexto Arquitectónico Crítico ("Cursos")
En este plugin, cuando se menciona **"crear curso"**, NO nos referimos a un curso real de Moodle (tabla `mdl_course`). Operan conceptualmente como una **"categoría padre" o contenedor lógico superior** gestionado internamente por el plugin para agrupar subcategorías y tests. 
La jerarquía de datos y visualización es estricta: 
`[Curso / Contenedor Padre (Gestión Interna)] -> [Subcategoría de único nivel] -> [Tests]`

---

## Requerimientos Funcionales a Implementar

### 1. Creación de "Cursos" (Contenedores Padre)
- **UI & Persistencia:** Añadir un botón en la pantalla principal del plugin para crear contenedores lógicos ("Cursos"). Desarrollar formulario con `moodleform`, validaciones de campos obligatorios, persistencia en base de datos mediante XMLDB (`local_testmanager_courses`) y actualización dinámica de la vista.
- **Acción derivada automática:** Al crear exitosamente un contenedor lógico, el sistema debe **crear automáticamente una categoría especial interna denominada "Papelera"** asociada a dicho curso.

### 2. Subcategorías dentro de cada "Curso"
- **Estructura:** Permitir crear subcategorías de un **único nivel** dentro de cada contenedor padre para organizar los tests.
- **Validaciones:** Asegurar la integridad referencial validando el `courseid` correspondiente en la tabla `local_testmanager_categories`.

### 3. Creación e Importación de Tests en el Banco de Preguntas
- **Importación CSV:** Añadir dentro de cada categoría la opción "Importar tests" con asignación de nombre e importación masiva de preguntas mediante archivo CSV.
- **Integración Nativa:** Adaptar la lógica nativa del banco de preguntas/importación de Moodle asociando el test creado a la categoría seleccionada y actualizando el contador de preguntas (`questioncount`).

### 4. Importación de Preguntas desde el Banco de Preguntas
- **Selección Avanzada:** Añadir la opción "Importar test desde el Banco de Preguntas" en la sección de creación/edición de tests.
- **Filtros y Búsqueda:** Permitir al usuario visualizar, filtrar, buscar y seleccionar preguntas existentes en el Banco de Preguntas de Moodle para incorporarlas al test actual.


### 6. Papelera de Tests
- **Gestión Visual:** La categoría especial "Papelera" (`istrash = 1`) debe diferenciarse visualmente con estilos basados en Bootstrap (compatibles con el tema Boost de Moodle).
- **Operatividad:** Permitir mover tests a la papelera mediante un icono dedicado (o *drag & drop* si aplica). Los tests en la papelera deben mostrar la cantidad de preguntas que contienen y quedar **estrictamente excluidos** de los procesos de importación en otras categorías.

### 7. Eliminación de Categorías y Tests
- **Borrado Rápido:** Agregar un icono de papelera para eliminación rápida en tests y categorías individuales.
- **Seguridad Transaccional:** Al eliminar una categoría con tests asociados, mostrar una advertencia de confirmación previa. Si se confirma, ejecutar una transacción en base de datos (`$DB->start_delegated_transaction()`) para eliminar la categoría y todos sus tests dependientes de forma definitiva, actualizando la interfaz dinámicamente.

---

## Especificaciones Técnicas y de Diseño
- **UI Spec / UX:** Utilizar componentes nativos de Bootstrap compatibles con el tema Boost de Moodle. Priorizar la robustez funcional y la lógica de negocio por encima de ajustes complejos de CSS personalizado (enfoque ágil orientado a funcionalidad).
- **Seguridad:** Uso estricto de `require_login()`, `context_system::instance()` (o de curso según aplique), `require_capability('local/testmanager:manage', $context)`, y validación de `$SESSION` / `sesskey` en formularios y peticiones AJAX.