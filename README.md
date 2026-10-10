# test-manager-library-3413974-api

> **Prueba técnica · Ficha ADSO 3413974**  
> Backend oficial de Manager Library desarrollado en PHP 8.2 con Laravel 11.

---

## 🛠️ Tecnologías y Arquitectura

- **Framework**: Laravel 11 (PHP 8.2).
- **Motor de Datos**: PostgreSQL 16 sobre esquema `library`.
- **Autenticación**: JWT HS256 sin estado con vigencia de 8 horas (`/api/v1/auth/login`).
- **Contrato de API**: Especificación estricta de `/api/v1` con cuerpo de error uniforme:
  ```json
  {
    "code": "BOOK_NOT_AVAILABLE",
    "message": "Mensaje en español...",
    "details": []
  }
  ```

---

## 📋 Reglas de Negocio Implementadas

1. **Gestión de Libros**: ISBN único de 13 dígitos, control de inventario positivo, baja lógica (`deleted_at`), rechazo de baja si existen préstamos abiertos (HTTP 409).
2. **Socios**: Cédula/documento único, prohibición de eliminación de socios.
3. **Préstamos Atómicos**:
   - Entre 1 y 3 libros por préstamo.
   - Prohibido repetir el mismo libro en el mismo préstamo (HTTP 422).
   - Bloqueo si el socio tiene más de 3 libros activos o préstamos vencidos (HTTP 409).
   - Congelamiento inmutable de título, ISBN y nombre de categoría en `loan_item`.
   - Plazo de vencimiento estricto de 14 días.
4. **Devoluciones**:
   - Devolución individual por línea con incremento automático de ejemplares disponibles.
   - Protección contra doble devolución (HTTP 409 `ITEM_ALREADY_RETURNED`).
5. **Reportes**:
   - Consulta agregada sobre valores congelados para estabilidad histórica (ADR-004).

---

## 🚀 Puesta en Marcha Local

1. Instalar dependencias:
   ```bash
   composer install
   ```

2. Configurar variables de entorno:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Ejecutar migraciones y semilla de administrador:
   ```bash
   php artisan migrate --force
   php artisan app:seed-admin
   ```

4. Servidor de desarrollo:
   ```bash
   php artisan serve --port=8000
   ```
