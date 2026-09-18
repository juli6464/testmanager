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
- **Tablas propias**: `local_testmanager_courses`, `local_testmanager_categories`, `local_testmanager_tests`.
- **Requisitos**: Moodle 4.1 o superior (usa la arquitectura de banco de preguntas de Moodle 4.x: `question_bank_entries`, `question_versions`, `question_references`).
