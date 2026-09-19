# Test Manager (`local_testmanager`)

Plugin de Moodle que ofrece una pantalla única para **organizar y crear cuestionarios (quiz) de forma masiva**, sin tener que entrar curso por curso ni usar el banco de preguntas nativo de Moodle.

> URL del plugin: `http://localhost/local/testmanager/index.php`
> También aparece en el menú de administración del sitio como **"Gestión de Tests"**.

---

## ¿Qué problema resuelve?

En Moodle, crear un cuestionario con 50 preguntas implica muchos pasos manuales. Este plugin permite:

- Subir un **archivo CSV** y que se genere automáticamente un cuestionario real de Moodle con todas sus preguntas de opción múltiple.
- Tener los tests **agrupados visualmente** por "curso" y "categoría", con contadores de tests y de preguntas.
- **Reutilizar** tests ya creados copiándolos a otra categoría.
- Enviar tests a una **papelera** en lugar de borrarlos, con opción de restaurar.

## Qué garantiza y qué no

**Sí garantiza:**

- **Localizar tests entre cientos.** El buscador propio del plugin (barra superior + filtros de curso/categoría) permite encontrar un test por nombre sin depender de la navegación nativa de Moodle por curso.
- **Vinculación real con el banco de preguntas.** Al importar un test a otro curso/categoría no se duplican las preguntas: el cuestionario destino apunta al mismo `questionbankentryid` de Moodle (con `version = null`, "siempre la última versión").
- **Editar una pregunta se propaga solo.** Como la pregunta se comparte por `questionbankentryid`, un cambio de contenido hecho desde el banco nativo aparece automáticamente en todos los cuestionarios donde esté ese test — esto es nativo de Moodle, no requiere nada del plugin.
- **Eliminar (o agregar) una pregunta también se propaga.** Esta es la pieza que el plugin sí tuvo que construir: cada vez que un test se importa a un curso/cuestionario (por CSV, "Importar desde Banco" o "Importar con Test Manager" desde `mod/quiz/edit.php`), se guarda un vínculo en `local_testmanager_test_links` entre el test **maestro** (el original) y ese cuestionario destino. Al cargar `index.php`, `local_testmanager_sync_all_masters()` compara las preguntas actuales del maestro contra cada cuestionario vinculado y **agrega o quita los slots que ya no coincidan**, usando la API nativa de mod_quiz (`quiz_add_quiz_question()` / `structure::remove_slot()`). Así, borrar o añadir una pregunta en el test maestro se traslada solo a todos los cursos donde se haya importado.
- **Contador de preguntas y fecha en vivo.** `question_count` y `timemodified` ya no quedan congelados al crear el test: `local_testmanager_recount_tests()` los recalcula contra `quiz_slots` cada vez que se listan tests en `index.php` o en el buscador del banco.
- **Editar/eliminar preguntas sigue siendo posible desde el banco nativo.** `question/edit.php` no se intercepta ni se redirige: es la pantalla donde el cliente sigue pudiendo modificar o borrar preguntas de un test ya creado.
- **Borrado seguro.** Enviar un test a la papelera o eliminarlo definitivamente solo afecta a ese cuestionario puntual (`course_delete_module`); no borra la pregunta compartida del banco si otros cuestionarios todavía la usan. Los vínculos de sincronización relacionados con el test borrado se limpian con `local_testmanager_cleanup_test_links()`.

**Limitaciones conocidas:**

- **La sincronización solo aplica hacia adelante.** Los tests que ya existían antes de esta funcionalidad se migran como "su propio maestro" (no hay forma de reconstruir qué copias venían de cuál original). El vínculo maestro→copias se registra desde ahora en cada nueva importación.
- **Un cuestionario con intentos ya realizados no se puede reestructurar.** Si el cuestionario destino ya tiene intentos de alumnos, Moodle bloquea agregar/quitar preguntas (`check_can_be_edited()`); la sincronización de ese cuestionario en particular se omite silenciosamente (se registra en el log de depuración) sin afectar a los demás.
- **"Curso" del plugin no es un curso real de Moodle** (ver sección siguiente): es solo una carpeta lógica. Los tests creados por CSV viven, por defecto, en el contexto del curso de portada (`SITEID`); solo al usar "Importar desde Banco" se crea (o reutiliza) un curso Moodle real por cada "curso" del plugin.
- **Sin historial de cambios propio.** El plugin no registra quién editó una pregunta ni cuándo; solo se apoya en las tablas nativas de Moodle (`question_versions`).

## Bugs corregidos

- **Preguntas duplicadas al importar con "Importar con Test Manager".** `ajax/import_bank_into_quiz.php` no comprobaba si el cuestionario destino ya tenía esa pregunta antes de agregarla; si el widget llegaba a inyectarse más de una vez en la misma carga de `mod/quiz/edit.php` (o el usuario repetía la acción), cada pregunta quedaba duplicada. Se corrigió en dos frentes: (1) el script ahora se salta cualquier `questionbankentryid` que el cuestionario destino ya tenga antes de insertarlo, y (2) el JS inyectado en `mod/quiz/edit.php` tiene un guard (`window.testmanagerQuizEditInit`) para que sus manejadores de clic nunca queden registrados dos veces.
- **"ID de módulo de curso no válido" en tests aún no importados a un curso real.** La reparación automática (`local_testmanager_repair_test_cms()`, antes `local_testmanager_repair_orphan_sections()`) excluía a propósito la portada del sitio (`SITEID`), justo donde viven los tests creados por CSV que todavía no se han importado a ningún curso. Ahora repara los cuestionarios de Test Manager sin importar en qué curso estén (incluida la portada), pero solo toca los cmid que son `quizid` de algún `local_testmanager_tests` — nunca otro contenido del curso.
- **Warnings al quitar varias preguntas de un test maestro en la misma sincronización.** `local_testmanager_sync_master_test()` reutilizaba el mismo objeto `structure` de mod_quiz para varias llamadas seguidas a `remove_slot()`; su caché interna de slots quedaba desincronizada tras la primera eliminación. Ahora se reconstruye el `structure` en cada eliminación, leyendo el slot actual directamente de la base de datos.

## Guía: ¿en qué dirección se propagan los cambios?

**La regla corta: banco → cursos reales, sí. Cursos reales → banco, no.**

El "test maestro" es la copia original que ves en `local/testmanager/index.php` (la primera vez que se creó ese test, antes de importarlo a ningún otro curso). Todo lo demás que se haya importado a partir de él (con "Importar desde Banco" o "Importar con Test Manager") es una **copia dependiente**. La sincronización siempre corre en un solo sentido: **el maestro manda, las copias obedecen.**

| Acción | ¿Dónde se hace? | ¿Se refleja en los demás? |
|---|---|---|
| Editar el **contenido** de una pregunta (texto, respuestas) | Desde el banco nativo (`question/edit.php`), **en cualquier lugar** donde esa pregunta aparezca | **Sí, siempre y en ambos sentidos.** No es cosa del plugin: es la misma pregunta de Moodle (`questionbankentryid`) en todos lados, así que basta con editarla una vez, sin importar desde qué curso se edite. |
| **Agregar** una pregunta al test | Desde el cuestionario del **test maestro** | Sí: en la próxima carga de `index.php`, se agrega también en todos los cursos donde se haya importado ese test. |
| **Eliminar** una pregunta del test | Desde el cuestionario del **test maestro** | Sí: se elimina también de todos los cursos donde se haya importado. |
| **Agregar o eliminar** una pregunta directamente en un **curso donde se importó** (no en el maestro) | Desde ese curso puntual | **No se traslada al maestro ni a los demás cursos.** Y ojo: en la próxima sincronización, ese cuestionario se "corrige" para volver a coincidir con el maestro — es decir, la pregunta que agregaste ahí se puede volver a quitar, o la que borraste ahí puede reaparecer, porque el plugin asume que el maestro es la única fuente de verdad. |

**En la práctica, esto significa:**
- Si el cliente quiere que un cambio (agregar/quitar pregunta) llegue a **todos** los cursos, debe hacerlo **desde el cuestionario del test original** (el que aparece como su propio maestro en `index.php`), no desde una de las copias ya importadas.
- Editar el **texto** de una pregunta sí puede hacerse desde cualquier copia y llega a todas partes por igual, porque ahí no hay "maestro" ni "copia": es la misma pregunta en Moodle.
- ¿Cómo saber si un test es el maestro o una copia? Por ahora no hay un indicador visual en `index.php` — se puede consultar con `SELECT * FROM local_testmanager_tests WHERE masterid = id` (masteros) vs `masterid != id` (copias). Si el cliente lo necesita, se puede agregar una etiqueta visual ("Original" / "Copia de...") en una siguiente iteración.

## Cómo se organiza la información

```
Curso (contenedor lógico del plugin)
 └── Categoría (un solo nivel)
      └── Test  ──> corresponde a un cuestionario (quiz) real de Moodle
 └── Papelera (categoría especial, se crea sola con cada curso)
```

**Importante:** el "Curso" del plugin **no es un curso real de Moodle**. Es solo una carpeta lógica para agrupar. Los cuestionarios sí son reales y se pueden abrir con el icono de enlace externo de cada test.

---

## Funcionalidades disponibles

| Función | Dónde está |
|---|---|
| Crear curso (contenedor) | Botón verde **"Crear Curso"** arriba a la derecha |
| Crear categoría | Botón **"Nueva Categoría"** |
| Importar test desde CSV | Botón **"Importar Test CSV"** dentro de cada curso |
| Copiar un test existente a otra categoría | Botón **"Importar desde Banco"** |
| Filtrar por curso / buscar test por nombre | Barra superior |
| Mover test a la papelera | Icono de papelera del test, o **arrastrándolo** sobre el botón "Papelera del Curso" |
| Restaurar o borrar definitivamente | Dentro del modal "Papelera del Curso" |
| Reordenar tests y categorías | **Arrastrar y soltar** (se guarda automáticamente) |
| Eliminar categoría o curso completo | Iconos de papelera / X, con modal de confirmación |
| Contraer / expandir un curso | Flecha a la izquierda del nombre |

Al borrar definitivamente un test, también se elimina el cuestionario real de Moodle asociado.

---

## Formato del CSV

Separador: **punto y coma (`;`)**. Se acepta UTF-8 con o sin BOM.

- Una línea que empieza con `*` define el **enunciado** de una pregunta.
- Las líneas siguientes (primera columna vacía) son las **opciones de respuesta**.
- Una `x` en la tercera columna marca la **respuesta correcta**.

```csv
*;¿Cuál es la capital de Colombia?;
;Bogotá;x
;Medellín;
;Cali;
*;¿Cuánto es 2 + 2?;
;3;
;4;x
```

Todas las preguntas se crean como **opción múltiple de respuesta única**, con valor 1 punto cada una.

---

## Cómo probarlo (paso a paso)

1. **Instalar**: copiar la carpeta en `moodle/local/testmanager` y entrar a *Administración del sitio* para completar la instalación (crea 3 tablas propias del plugin).
2. Entrar como **administrador** (o un rol con la capacidad `local/testmanager:manage`) a `http://localhost/local/testmanager/index.php`.
3. Pulsar **"Crear Curso"** y ponerle un nombre, por ejemplo `Matemáticas`. Se creará también su papelera automáticamente.
4. Pulsar **"Nueva Categoría"**, elegir el curso recién creado y nombrarla, por ejemplo `Parcial 1`.
5. Pulsar **"Importar Test CSV"**: elegir la categoría destino, poner un nombre al test y subir un CSV con el formato de arriba.
6. Verificar que el test aparece con su **contador de preguntas** correcto.
7. Pulsar el icono de **enlace externo** del test: debe abrir el cuestionario nativo de Moodle con las preguntas ya cargadas.
8. Probar **"Importar desde Banco"**: seleccionar uno o varios tests existentes y copiarlos a otra categoría.
9. **Arrastrar** un test sobre el botón "Papelera del Curso", abrir la papelera y comprobar que se puede **restaurar** (vuelve a su categoría original) o borrar definitivamente.
10. **Arrastrar** tests entre categorías e incluso entre cursos, y recargar la página para confirmar que el orden se conserva.

---

## Notas técnicas

- **Permisos**: todas las acciones requieren `local/testmanager:manage` (por defecto: *manager* y *editingteacher*). Todas las peticiones AJAX y de borrado validan `sesskey`.
- **Borrados**: las eliminaciones de curso/categoría/papelera se hacen dentro de una transacción de base de datos.
- **Tablas propias**: `local_testmanager_courses`, `local_testmanager_categories`, `local_testmanager_tests` (incluye `masterid` y `timemodified`), `local_testmanager_test_links` (vínculos test maestro → cmid destino, usados por `local_testmanager_sync_master_test()`).
- **Requisitos**: Moodle 4.1 o superior (usa la arquitectura de banco de preguntas de Moodle 4.x: `question_bank_entries`, `question_versions`, `question_references`).
