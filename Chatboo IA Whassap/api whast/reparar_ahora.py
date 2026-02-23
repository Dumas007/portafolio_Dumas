#!/usr/bin/env python3
"""
REPARADOR INMEDIATO - Ejecutar mientras el servidor está corriendo
"""

import mysql.connector
import logging
from datetime import datetime

# Configurar logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

def get_connection():
    try:
        conn = mysql.connector.connect(
            host="srv764.hstgr.io",
            user="u960874690_Dgpt007",
            password="Dumas$007",
            database="u960874690_Dumas_Gpt",
            connection_timeout=30
        )
        logger.info("✅ Conexión a BD establecida correctamente")
        return conn
    except mysql.connector.Error as e:
        logger.error(f"❌ Error de conexión a BD: {e}")
        return None

def reparar_relaciones_especificas():
    """Repara las relaciones específicas que muestra el diagnóstico"""
    conn = get_connection()
    if not conn:
        return
    
    cursor = conn.cursor()
    
    try:
        print("🛠 INICIANDO REPARACIÓN DE RELACIONES NULL")
        print("=" * 50)
        
        # REPARACIÓN ESPECÍFICA: Consulta ID 1 con Ticket ID 25
        print("🔧 Reparando relación específica:")
        print("   📝 Consulta FAQ ID: 1 → Ticket ID: 25")
        
        # Actualizar consulta_faq
        cursor.execute("""
            UPDATE consultas_faq 
            SET ticket_relacionado_id = 25 
            WHERE id = 1 AND ticket_relacionado_id IS NULL
        """)
        
        # Actualizar ticket
        cursor.execute("""
            UPDATE tickets 
            SET consulta_relacionada_id = 1 
            WHERE id = 25 AND consulta_relacionada_id IS NULL
        """)
        
        # Buscar y reparar otras relaciones automáticamente
        print("🔄 Buscando más relaciones para reparar...")
        
        cursor.execute("""
            SELECT c.id as consulta_id, c.numero, c.fecha_consulta,
                   t.id as ticket_id, t.fecha_creacion,
                   TIMESTAMPDIFF(MINUTE, c.fecha_consulta, t.fecha_creacion) as diff_minutos
            FROM consultas_faq c
            JOIN tickets t ON c.numero = t.numero
            WHERE (c.ticket_relacionado_id IS NULL OR t.consulta_relacionada_id IS NULL)
            ORDER BY c.fecha_consulta DESC
        """)
        
        relaciones_pendientes = cursor.fetchall()
        
        print(f"📋 Encontradas {len(relaciones_pendientes)} relaciones pendientes")
        
        for rel in relaciones_pendientes:
            consulta_id, numero, fecha_consulta, ticket_id, fecha_creacion, diff_minutos = rel
            
            # Solo relacionar si la diferencia de tiempo es razonable (menos de 30 minutos)
            if abs(diff_minutos) <= 30:
                print(f"   🔗 Relacionando: Consulta {consulta_id} ↔ Ticket {ticket_id} (Diff: {diff_minutos} min)")
                
                # Actualizar consulta_faq
                cursor.execute("""
                    UPDATE consultas_faq 
                    SET ticket_relacionado_id = %s 
                    WHERE id = %s AND ticket_relacionado_id IS NULL
                """, (ticket_id, consulta_id))
                
                # Actualizar ticket
                cursor.execute("""
                    UPDATE tickets 
                    SET consulta_relacionada_id = %s 
                    WHERE id = %s AND consulta_relacionada_id IS NULL
                """, (consulta_id, ticket_id))
        
        conn.commit()
        print("✅ Todas las relaciones han sido actualizadas en la BD")
        
        # VERIFICAR LOS CAMBIOS
        print("\n🔍 VERIFICANDO REPARACIONES...")
        print("-" * 40)
        
        # Consultas reparadas
        cursor.execute("""
            SELECT id, numero, ticket_relacionado_id
            FROM consultas_faq 
            WHERE ticket_relacionado_id IS NOT NULL
            ORDER BY id
        """)
        
        consultas_reparadas = cursor.fetchall()
        print(f"📊 Consultas con ticket relacionado: {len(consultas_reparadas)}")
        for consulta in consultas_reparadas:
            print(f"   ✅ Consulta {consulta[0]} (Num: {consulta[1]}) → Ticket: {consulta[2]}")
        
        # Tickets reparados
        cursor.execute("""
            SELECT id, numero, consulta_relacionada_id
            FROM tickets 
            WHERE consulta_relacionada_id IS NOT NULL
            ORDER BY id
        """)
        
        tickets_reparados = cursor.fetchall()
        print(f"📊 Tickets con consulta relacionada: {len(tickets_reparados)}")
        for ticket in tickets_reparados:
            print(f"   ✅ Ticket {ticket[0]} (Num: {ticket[1]}) → Consulta: {ticket[2]}")
        
        # RELACIONES CRUZADAS COMPLETAS
        print("\n🔗 RELACIONES CRUZADAS COMPLETAS:")
        print("-" * 40)
        
        cursor.execute("""
            SELECT c.id as consulta_id, c.ticket_relacionado_id, 
                   t.id as ticket_id, t.consulta_relacionada_id,
                   c.numero, c.fecha_consulta
            FROM consultas_faq c
            JOIN tickets t ON c.ticket_relacionado_id = t.id
            ORDER BY c.id
        """)
        
        relaciones_completas = cursor.fetchall()
        
        if relaciones_completas:
            for rel in relaciones_completas:
                consulta_id, ticket_en_consulta, ticket_id, consulta_en_ticket, numero, fecha = rel
                estado = "✅ BIEN RELACIONADO" if consulta_id == consulta_en_ticket and ticket_id == ticket_en_consulta else "⚠️ RELACIÓN INCONSISTENTE"
                print(f"   {estado}")
                print(f"     Consulta {consulta_id} → Ticket: {ticket_en_consulta}")
                print(f"     Ticket {ticket_id} → Consulta: {consulta_en_ticket}")
                print(f"     Número: {numero}, Fecha: {fecha}")
                print()
        else:
            print("   ℹ️ No hay relaciones cruzadas completas aún")
            
    except Exception as e:
        logger.error(f"❌ Error reparando relaciones: {e}")
        conn.rollback()
        print(f"❌ ERROR: {e}")
    finally:
        cursor.close()
        conn.close()

def mostrar_estado_actual():
    """Muestra el estado actual de las relaciones"""
    conn = get_connection()
    if not conn:
        return
    
    cursor = conn.cursor()
    
    try:
        print("\n📊 ESTADO ACTUAL DE LA BASE DE DATOS:")
        print("=" * 50)
        
        # Total de consultas
        cursor.execute("SELECT COUNT(*) FROM consultas_faq")
        total_consultas = cursor.fetchone()[0]
        
        # Consultas con relación
        cursor.execute("SELECT COUNT(*) FROM consultas_faq WHERE ticket_relacionado_id IS NOT NULL")
        consultas_con_relacion = cursor.fetchone()[0]
        
        # Total de tickets
        cursor.execute("SELECT COUNT(*) FROM tickets")
        total_tickets = cursor.fetchone()[0]
        
        # Tickets con relación
        cursor.execute("SELECT COUNT(*) FROM tickets WHERE consulta_relacionada_id IS NOT NULL")
        tickets_con_relacion = cursor.fetchone()[0]
        
        print(f"📝 CONSULTAS FAQ: {consultas_con_relacion}/{total_consultas} con relación ({consultas_con_relacion/total_consultas*100:.1f}%)")
        print(f"🎫 TICKETS: {tickets_con_relacion}/{total_tickets} con relación ({tickets_con_relacion/total_tickets*100:.1f}%)")
        
        # Últimas 3 consultas
        print("\n🔍 ÚLTIMAS CONSULTAS:")
        cursor.execute("""
            SELECT id, numero, pregunta_usuario, ticket_relacionado_id, fecha_consulta
            FROM consultas_faq 
            ORDER BY id DESC 
            LIMIT 3
        """)
        
        consultas = cursor.fetchall()
        for consulta in consultas:
            estado = "✅ CON TICKET" if consulta[3] else "❌ SIN TICKET"
            print(f"   {estado} - ID: {consulta[0]}, Num: {consulta[1]}")
            print(f"      Pregunta: {consulta[2][:50]}...")
            print(f"      Ticket Rel: {consulta[3]}, Fecha: {consulta[4]}")
            print()
            
    except Exception as e:
        print(f"❌ Error mostrando estado: {e}")
    finally:
        cursor.close()
        conn.close()

if __name__ == '__main__':
    print("🚀 REPARADOR INMEDIATO DE RELACIONES NULL")
    print("Ejecutando mientras el servidor Flask está activo...")
    print()
    
    # Mostrar estado antes
    mostrar_estado_actual()
    
    # Ejecutar reparación
    reparar_relaciones_especificas()
    
    # Mostrar estado después
    mostrar_estado_actual()
    
    print("🎉 REPARACIÓN COMPLETADA!")
    print("\n💡 Ahora reinicia el servidor Flask para ver los cambios en el diagnóstico inicial")