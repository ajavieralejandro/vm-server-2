# Frontend: Evitar Reintentos Infinitos en Autenticación

## ❌ Problema: Loop Infinito

Cuando recibimos 401/403, el frontend no debe reintentar indefinidamente. Ejemplo de **MAL** código:

```javascript
// ❌ MALO - Loop infinito
async function fetchSocios() {
  while (true) {
    try {
      const response = await fetch('/api/admin/profesores/6/socios');
      if (response.ok) {
        return await response.json();
      }
      // Intentar de nuevo infinitamente
    } catch (error) {
      console.error(error);
    }
  }
}
```

---

## ✅ Solución: Manejo Correcto de Errores

```javascript
// Estado del componente
const [data, setData] = useState(null);
const [error, setError] = useState(null);
const [loading, setLoading] = useState(false);

async function fetchSocios() {
  setLoading(true);
  setError(null);

  try {
    const response = await fetch('/api/admin/profesores/6/socios', {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${getToken()}`  // Obtener token guardado
      }
    });

    // ✅ Manejar respuesta correctamente
    if (response.ok) {
      const result = await response.json();
      setData(result.data);
      return result;
    }

    // ✅ Manejar errores específicos - NO reintentar
    if (response.status === 401) {
      setError('Sesión expirada. Por favor, inicia sesión nuevamente.');
      // Limpiar token y redirigir a login
      localStorage.removeItem('token');
      window.location.href = '/login';
      return;
    }

    if (response.status === 403) {
      setError('No tienes permisos para acceder a este recurso.');
      // NO reintentar - el usuario no tiene autorización
      return;
    }

    if (response.status === 422) {
      const result = await response.json();
      setError(`Error de validación: ${result.message}`);
      // Mostrar errores específicos si existen
      console.error('Validation errors:', result.errors);
      return;
    }

    // Otros errores 4xx
    if (response.status >= 400 && response.status < 500) {
      setError(`Error del cliente: ${response.status}`);
      return;
    }

    // Errores 5xx - aquí podría haber reintento con backoff
    if (response.status >= 500) {
      setError('Error del servidor. Intenta más tarde.');
      // Opcional: reintento con exponential backoff
      return;
    }

  } catch (err) {
    setError(`Error de conexión: ${err.message}`);
    console.error('Fetch error:', err);
  } finally {
    setLoading(false);
  }
}
```

---

## 📋 Matriz de Decisiones

| Status | Acción | Reintentar | Motivo |
|--------|--------|-----------|--------|
| **200** | Usar datos | ❌ | Éxito |
| **401** | Logout + Login | ❌ | Sesión expirada |
| **403** | Mostrar error | ❌ | Permisos insuficientes |
| **404** | Mostrar error | ❌ | Recurso no existe |
| **422** | Mostrar errores validación | ❌ | Datos inválidos |
| **429** | Esperar + Reintentar | ✅ | Rate limit (con backoff) |
| **500** | Esperar + Reintentar | ✅ | Error temporal del servidor |
| **503** | Esperar + Reintentar | ✅ | Servidor en mantenimiento |

---

## 🔄 Reintento con Exponential Backoff (Opcional)

Si necesitas reintentar algunos errores 5xx:

```javascript
async function fetchWithRetry(url, options = {}, maxRetries = 3, delayMs = 1000) {
  for (let attempt = 0; attempt <= maxRetries; attempt++) {
    try {
      const response = await fetch(url, {
        headers: {
          'Accept': 'application/json',
          'Authorization': `Bearer ${getToken()}`,
          ...options.headers
        },
        ...options
      });

      // ✅ NO reintentar en 4xx
      if (response.status >= 400 && response.status < 500) {
        return response;
      }

      // ✅ Éxito
      if (response.ok) {
        return response;
      }

      // ✅ Reintento solo en 5xx
      if (response.status >= 500 && attempt < maxRetries) {
        const delay = delayMs * Math.pow(2, attempt);  // Exponential backoff
        console.log(`Reintentando en ${delay}ms... (intento ${attempt + 1}/${maxRetries})`);
        await new Promise(resolve => setTimeout(resolve, delay));
        continue;
      }

      return response;
    } catch (err) {
      // Error de red - reintentar
      if (attempt < maxRetries) {
        const delay = delayMs * Math.pow(2, attempt);
        console.log(`Error de red. Reintentando en ${delay}ms...`);
        await new Promise(resolve => setTimeout(resolve, delay));
        continue;
      }
      throw err;
    }
  }
}

// Uso
async function fetchSocios() {
  setLoading(true);
  setError(null);

  try {
    const response = await fetchWithRetry('/api/admin/profesores/6/socios', {
      method: 'GET'
    }, 3, 1000);

    if (response.ok) {
      const result = await response.json();
      setData(result.data);
    } else if (response.status === 401) {
      setError('Sesión expirada');
      localStorage.removeItem('token');
      window.location.href = '/login';
    } else if (response.status === 403) {
      setError('Acceso denegado');
    } else {
      const result = await response.json();
      setError(result.message);
    }
  } catch (err) {
    setError(`Error: ${err.message}`);
  } finally {
    setLoading(false);
  }
}
```

---

## 🎯 Buenas Prácticas

1. **Siempre incluir headers**: `Accept: application/json` + `Authorization: Bearer <token>`
2. **No reintentar en 401/403**: Son errores de autorización que no se resuelven automáticamente
3. **Establecer límites de reintento**: Máximo 3-5 reintentos con exponential backoff
4. **Mostrar errores al usuario**: Especialmente en 401 y 403
5. **Limpiar estado en error**: Setear `setLoading(false)` y mostrar mensaje de error
6. **Log de debugging**: Registrar requests/responses en desarrollo

---

## 📝 Ejemplo Completo: Hook de React

```javascript
// hooks/useFetchSocios.js
import { useState, useEffect } from 'react';

export function useFetchSocios(profesorId, perPage = 1000) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      setError(null);

      try {
        const token = localStorage.getItem('token');
        
        if (!token) {
          setError('No estás autenticado');
          setLoading(false);
          return;
        }

        const url = `/api/admin/profesores/${profesorId}/socios?per_page=${perPage}`;
        
        const response = await fetch(url, {
          headers: {
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`
          }
        });

        if (response.ok) {
          const result = await response.json();
          setData(result.data);
        } else if (response.status === 401) {
          setError('Sesión expirada. Por favor, inicia sesión nuevamente.');
          localStorage.removeItem('token');
          // window.location.href = '/login';  // Redirigir si es necesario
        } else if (response.status === 403) {
          setError('No tienes permisos para acceder a este recurso.');
        } else {
          const result = await response.json();
          setError(result.message || 'Error al obtener datos');
        }
      } catch (err) {
        setError(`Error de conexión: ${err.message}`);
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [profesorId, perPage]);

  return { data, error, loading };
}

// Uso en componente
function SociosList({ profesorId }) {
  const { data: socios, error, loading } = useFetchSocios(profesorId);

  if (loading) return <p>Cargando...</p>;
  if (error) return <p style={{ color: 'red' }}>{error}</p>;
  if (!socios || socios.length === 0) return <p>No hay socios</p>;

  return (
    <table>
      <tbody>
        {socios.map(socio => (
          <tr key={socio.id}>
            <td>{socio.dni}</td>
            <td>{socio.apellido}, {socio.nombre}</td>
            <td>{socio.email}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
```

---

## ✅ Resumen

- ❌ NO reintentar en 401/403
- ✅ Mostrar error y permitir al usuario tomar acción (logout, etc.)
- ✅ Usar `setLoading(false)` después de cualquier resultado
- ✅ Incluir headers correctos: `Accept: application/json` + `Authorization: Bearer`
- ✅ Manejar cada status code específicamente

