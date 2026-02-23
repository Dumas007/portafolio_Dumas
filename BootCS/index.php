<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chatbot de Soporte - Tu Pedido</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .chat-container {
            width: 100%;
            max-width: 1000px;
            height: 85vh;
            background-color: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            opacity: 0;
            transform: translateY(20px);
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .chat-header {
            background: linear-gradient(to right, #1e3c72, #2a5298);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-header h1 {
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .chat-header i {
            font-size: 1.8rem;
        }
        
        .customer-email {
            font-size: 0.9rem;
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .chat-main {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        
        .chat-sidebar {
            width: 300px;
            background-color: #f8f9fa;
            border-right: 1px solid #e9ecef;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            overflow-y: auto;
        }
        
        .user-info {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            text-align: center;
            animation: slideInLeft 0.5s ease-out;
        }
        
        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(to right, #4f6df5, #3a56e5);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: white;
            font-size: 2rem;
            font-weight: bold;
        }
        
        .user-email {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            font-size: 1rem;
            word-break: break-all;
        }
        
        .user-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-delivered {
            background-color: #e7f7ef;
            color: #0ca750;
        }
        
        .status-pending {
            background-color: #fff4e6;
            color: #f97316;
        }
        
        .order-details {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            animation: slideInLeft 0.5s ease-out 0.1s both;
        }
        
        .order-details h3 {
            color: #1e3c72;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }
        
        .detail-item {
            margin-bottom: 12px;
        }
        
        .detail-label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 3px;
        }
        
        .detail-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: #333;
        }
        
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 20px;
            overflow: hidden;
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding-right: 10px;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .message {
            max-width: 80%;
            padding: 12px 16px;
            border-radius: 18px;
            line-height: 1.4;
            word-wrap: break-word;
            animation: fadeIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .user-message {
            align-self: flex-end;
            background-color: #1e3c72;
            color: white;
            border-bottom-right-radius: 5px;
        }
        
        .bot-message {
            align-self: flex-start;
            background-color: #f1f3f9;
            color: #333;
            border-bottom-left-radius: 5px;
        }
        
        .message-header {
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .user-message .message-header {
            color: rgba(255, 255, 255, 0.9);
        }
        
        .bot-message .message-header {
            color: #1e3c72;
        }
        
        .chat-input-area {
            display: flex;
            gap: 10px;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
        }
        
        .chat-input {
            flex: 1;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.3s;
        }
        
        .chat-input:focus {
            border-color: #1e3c72;
        }
        
        .send-button {
            background: linear-gradient(to right, #1e3c72, #2a5298);
            color: white;
            border: none;
            border-radius: 10px;
            width: 50px;
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.3s;
        }
        
        .send-button:hover {
            background: linear-gradient(to right, #2a5298, #3a68c0);
            transform: scale(1.05);
        }
        
        .loading-indicator {
            display: none;
            align-items: center;
            gap: 8px;
            color: #666;
            font-size: 0.9rem;
            padding: 10px 16px;
            background-color: #f1f3f9;
            border-radius: 18px;
            align-self: flex-start;
            border-bottom-left-radius: 5px;
            margin-bottom: 10px;
        }
        
        .loading-dots {
            display: flex;
            gap: 4px;
        }
        
        .loading-dots span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #1e3c72;
            animation: bounce 1.4s infinite ease-in-out both;
        }
        
        .loading-dots span:nth-child(1) { animation-delay: -0.32s; }
        .loading-dots span:nth-child(2) { animation-delay: -0.16s; }
        
        @keyframes bounce {
            0%, 80%, 100% { transform: scale(0); }
            40% { transform: scale(1.0); }
        }
        
        .welcome-message {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #1e3c72;
            animation: fadeIn 0.5s ease-out;
        }
        
        .welcome-message h3 {
            color: #1e3c72;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .error-message {
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
            border-radius: 10px;
            padding: 20px;
            margin: 20px;
            border-left: 4px solid #d32f2f;
            text-align: center;
        }
        
        .error-message h3 {
            color: #d32f2f;
            margin-bottom: 10px;
        }
        
        .retry-button {
            background: linear-gradient(to right, #1e3c72, #2a5298);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            margin-top: 10px;
            transition: all 0.3s;
        }
        
        .retry-button:hover {
            background: linear-gradient(to right, #2a5298, #3a68c0);
        }
        
        @media (max-width: 768px) {
            .chat-sidebar {
                display: none;
            }
            
            .chat-container {
                height: 95vh;
            }
            
            .message {
                max-width: 90%;
            }
            
            .customer-email {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="chat-container" id="chat-container">
        <div class="chat-header">
            <h1><i class="fas fa-headset"></i> Soporte al Cliente</h1>
            <div class="customer-email" id="customer-email">Cargando...</div>
        </div>
        
        <div class="chat-main">
            <div class="chat-sidebar">
                <div class="user-info" id="user-info">
                    <h3><i class="fas fa-user-circle"></i> Información del Cliente</h3>
                    <div class="user-avatar" id="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <p class="user-email" id="user-email">Cargando información...</p>
                    <p>Estado: <span class="user-status status-pending" id="user-status">Cargando...</span></p>
                </div>
                
                <div class="order-details" id="order-details">
                    <h3><i class="fas fa-shopping-cart"></i> Detalles del Pedido</h3>
                    <div class="detail-item">
                        <div class="detail-label">Número de Pedido:</div>
                        <div class="detail-value" id="order-number">-</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Teléfono:</div>
                        <div class="detail-value" id="phone-number">-</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Dirección de Envío:</div>
                        <div class="detail-value" id="delivery-address">-</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Dirección de Recolección:</div>
                        <div class="detail-value" id="pickup-address">-</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Estado:</div>
                        <div class="detail-value" id="order-status">-</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Fecha:</div>
                        <div class="detail-value" id="order-date">-</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Última Actualización:</div>
                        <div class="detail-value" id="order-update">-</div>
                    </div>
                </div>
            </div>
            
            <div class="chat-area">
                <div class="chat-messages" id="chat-messages">
                    <!-- Mensajes se cargarán aquí dinámicamente -->
                </div>
                
                <div class="loading-indicator" id="loading-indicator">
                    <div class="loading-dots">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                    Cargando tu información...
                </div>
                
                <div class="chat-input-area">
                    <input type="text" class="chat-input" id="chat-input" placeholder="Escribe tu pregunta aquí..." autocomplete="off" disabled>
                    <button class="send-button" id="send-button" disabled>
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Variable global para almacenar la información del cliente
        let customerData = null;
        
        // Función para obtener parámetros de la URL
        function getQueryParam(param) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(param);
        }
        
        // Función para decodificar email codificado
        function decodeEmail(email) {
            if (!email) return null;
            // Decodificar URL (por si viene codificado)
            try {
                return decodeURIComponent(email.replace(/ /g, '+'));
            } catch (e) {
                return email;
            }
        }
        
        // Función para cargar información del cliente
        async function loadCustomerInfo() {
            const emailParam = getQueryParam('email');
            
            if (!emailParam) {
                showErrorMessage("No se proporcionó un correo electrónico en la URL.");
                return;
            }
            
            const customerEmail = decodeEmail(emailParam);
            
            // Mostrar email en el header
            document.getElementById('customer-email').textContent = customerEmail;
            
            // Mostrar indicador de carga
            document.getElementById('loading-indicator').style.display = 'flex';
            
            try {
                // Simulación de conexión a Google Sheets
                // En producción, aquí harías una llamada a tu servidor PHP
                customerData = await fetchCustomerData(customerEmail);
                
                if (customerData) {
                    // Ocultar indicador de carga
                    document.getElementById('loading-indicator').style.display = 'none';
                    
                    // Mostrar información del cliente
                    displayCustomerInfo(customerData);
                    
                    // Mostrar mensaje de bienvenida
                    showWelcomeMessage(customerData);
                    
                    // Habilitar chat
                    enableChat();
                } else {
                    showErrorMessage(`No se encontró información para el correo: ${customerEmail}`);
                }
            } catch (error) {
                console.error('Error al cargar información:', error);
                showErrorMessage("Error al conectar con la base de datos. Por favor, intenta nuevamente.");
            }
        }
        
        // Función para simular la obtención de datos (en producción, esto se conectaría a Google Sheets)
        async function fetchCustomerData(email) {
            // Base de datos simulada (en producción esto vendría de Google Sheets)
            const ordersDatabase = [
                {
                    email: "egeovanny@gmail.com",
                    phone: "454578465",
                    order: "0-44541",
                    address: "Guatemala",
                    deliveryAddress: "Panama",
                    pickupAddress: "Salvador",
                    status: "Delivered",
                    date: "26/12/2025",
                    update: "Fri Dec 26 2025 13:35:14 GMT-0600 (Central Standard Time)"
                },
                {
                    email: "casdsad@sdfsf.com",
                    phone: "456456464",
                    order: "0-44545",
                    address: "Potén",
                    deliveryAddress: "Salvador",
                    pickupAddress: "Salvador",
                    status: "Delivered",
                    date: "26/12/2025",
                    update: "26/12/2025"
                },
                {
                    email: "sofia.mencos@sttlg.us",
                    phone: "6465",
                    order: "0-445400",
                    address: "Potén",
                    deliveryAddress: "Salvador",
                    pickupAddress: "Salvador",
                    status: "Delivered",
                    date: "26/12/2025",
                    update: "26/12/2025"
                },
                {
                    email: "it@gmail.com",
                    phone: "54650465650",
                    order: "0-445444",
                    address: "Bali",
                    deliveryAddress: "Honduras",
                    pickupAddress: "Hopndurdsas",
                    status: "Delivered",
                    date: "26/12/2025",
                    update: "26/12/2025"
                }
            ];
            
            // Simular tiempo de respuesta de red
            await new Promise(resolve => setTimeout(resolve, 800));
            
            // Buscar cliente por email
            const normalizedEmail = email.toLowerCase().trim();
            
            // Buscar coincidencia exacta
            let customer = ordersDatabase.find(order => 
                order.email.toLowerCase() === normalizedEmail
            );
            
            // Si no encuentra coincidencia exacta, buscar parcial
            if (!customer) {
                customer = ordersDatabase.find(order => 
                    order.email.toLowerCase().includes(normalizedEmail) || 
                    normalizedEmail.includes(order.email.toLowerCase())
                );
            }
            
            return customer;
        }
        
        // Función para mostrar información del cliente
        function displayCustomerInfo(data) {
            // Actualizar información del usuario
            document.getElementById('user-email').textContent = data.email;
            
            // Actualizar avatar con inicial
            const avatar = document.getElementById('user-avatar');
            const nameInitial = data.email.charAt(0).toUpperCase();
            avatar.innerHTML = `<span style="font-size: 2rem; font-weight: bold;">${nameInitial}</span>`;
            
            // Actualizar estado
            const statusElement = document.getElementById('user-status');
            statusElement.textContent = data.status;
            statusElement.className = `user-status status-${data.status.toLowerCase()}`;
            
            // Actualizar detalles del pedido
            document.getElementById('order-number').textContent = data.order;
            document.getElementById('phone-number').textContent = data.phone;
            document.getElementById('delivery-address').textContent = data.deliveryAddress;
            document.getElementById('pickup-address').textContent = data.pickupAddress;
            document.getElementById('order-status').textContent = data.status;
            document.getElementById('order-date').textContent = data.date;
            document.getElementById('order-update').textContent = data.update;
        }
        
        // Función para mostrar mensaje de bienvenida
        function showWelcomeMessage(data) {
            const chatMessages = document.getElementById('chat-messages');
            
            // Mensaje de bienvenida
            const welcomeDiv = document.createElement('div');
            welcomeDiv.className = 'welcome-message';
            welcomeDiv.innerHTML = `
                <h3><i class="fas fa-check-circle"></i> ¡Hola! Tu información ha sido cargada</h3>
                <p>He encontrado tu pedido <strong>${data.order}</strong> en nuestro sistema. 
                Tu pedido está actualmente marcado como <strong>"${data.status}"</strong>.</p>
                <p>¿En qué puedo ayudarte? Puedes preguntarme sobre el estado, dirección de envío, 
                fecha de entrega o cualquier otra duda sobre tu pedido.</p>
            `;
            chatMessages.appendChild(welcomeDiv);
            
            // Ejemplos de preguntas
            addBotMessage("Puedes preguntarme cosas como:");
            addBotMessage("• \"¿Cuál es el estado actual de mi pedido?\"");
            addBotMessage("• \"¿Cuál es la dirección de envío?\"");
            addBotMessage("• \"¿Puedes darme el número de teléfono registrado?\"");
            addBotMessage("• \"¿Cuál es la fecha de mi pedido?\"");
            
            // Desplazar al final
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        
        // Función para mostrar mensaje de error
        function showErrorMessage(message) {
            const chatContainer = document.getElementById('chat-container');
            const chatMain = document.querySelector('.chat-main');
            
            // Crear mensaje de error
            const errorDiv = document.createElement('div');
            errorDiv.className = 'error-message';
            errorDiv.innerHTML = `
                <h3><i class="fas fa-exclamation-triangle"></i> Error</h3>
                <p>${message}</p>
                <button class="retry-button" onclick="location.reload()">Reintentar</button>
            `;
            
            // Reemplazar contenido del chat
            chatMain.innerHTML = '';
            chatMain.appendChild(errorDiv);
            
            // Ocultar indicador de carga si está visible
            document.getElementById('loading-indicator').style.display = 'none';
        }
        
        // Función para habilitar el chat
        function enableChat() {
            const chatInput = document.getElementById('chat-input');
            const sendButton = document.getElementById('send-button');
            
            chatInput.disabled = false;
            sendButton.disabled = false;
            chatInput.placeholder = "Escribe tu pregunta aquí...";
            chatInput.focus();
            
            // Configurar eventos del chat
            setupChatEvents();
        }
        
        // Función para configurar eventos del chat
        function setupChatEvents() {
            const chatInput = document.getElementById('chat-input');
            const sendButton = document.getElementById('send-button');
            
            // Enviar mensaje al presionar Enter
            chatInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    sendMessage();
                }
            });
            
            // Enviar mensaje al hacer clic en el botón
            sendButton.addEventListener('click', sendMessage);
        }
        
        // Función para enviar mensaje
        function sendMessage() {
            const chatInput = document.getElementById('chat-input');
            const message = chatInput.value.trim();
            
            if (!message || !customerData) return;
            
            // Mostrar mensaje del usuario
            addUserMessage(message);
            chatInput.value = '';
            
            // Mostrar indicador de carga
            const loadingIndicator = document.getElementById('loading-indicator');
            loadingIndicator.style.display = 'flex';
            loadingIndicator.querySelector('.loading-dots + span').textContent = "Procesando tu pregunta...";
            
            // Procesar respuesta después de un breve retraso
            setTimeout(() => {
                const response = processQuestion(message);
                addBotMessage(response);
                
                // Ocultar indicador de carga
                loadingIndicator.style.display = 'none';
                
                // Desplazar al final
                const chatMessages = document.getElementById('chat-messages');
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }, 600);
        }
        
        // Función para procesar preguntas
        function processQuestion(question) {
            const lowerQuestion = question.toLowerCase();
            
            // Identificar qué información solicita el usuario
            if (lowerQuestion.includes('pedido') || lowerQuestion.includes('order') || lowerQuestion.includes('número')) {
                return `El número de tu pedido es: ${customerData.order}`;
            } else if (lowerQuestion.includes('teléfono') || lowerQuestion.includes('phone') || lowerQuestion.includes('contacto')) {
                return `Tu número de teléfono registrado es: ${customerData.phone}`;
            } else if (lowerQuestion.includes('dirección') || lowerQuestion.includes('address') || lowerQuestion.includes('envío') || lowerQuestion.includes('delivery')) {
                return `Tu dirección de envío es: ${customerData.deliveryAddress}`;
            } else if (lowerQuestion.includes('recolección') || lowerQuestion.includes('pickup') || lowerQuestion.includes('recoger')) {
                return `La dirección de recolección es: ${customerData.pickupAddress}`;
            } else if (lowerQuestion.includes('estado') || lowerQuestion.includes('status')) {
                let statusText = `El estado actual de tu pedido es: ${customerData.status}`;
                if (customerData.status === "Delivered") {
                    statusText += " (Entregado)";
                }
                return statusText;
            } else if (lowerQuestion.includes('fecha') || lowerQuestion.includes('date')) {
                return `La fecha de tu pedido es: ${customerData.date}`;
            } else if (lowerQuestion.includes('actualización') || lowerQuestion.includes('update') || lowerQuestion.includes('última')) {
                return `La última actualización fue: ${customerData.update}`;
            } else if (lowerQuestion.includes('hola') || lowerQuestion.includes('buenos') || lowerQuestion.includes('buenas')) {
                return `¡Hola! ¿En qué más puedo ayudarte con tu pedido ${customerData.order}?`;
            } else if (lowerQuestion.includes('gracias') || lowerQuestion.includes('agradecido') || lowerQuestion.includes('thanks')) {
                return `¡De nada! Estoy aquí para ayudarte. ¿Necesitas alguna otra información sobre tu pedido?`;
            } else if (lowerQuestion.includes('dirección') || lowerQuestion.includes('ubicación') || lowerQuestion.includes('location')) {
                return `Tu dirección registrada es: ${customerData.address}`;
            } else {
                return `He entendido tu pregunta: "${question}". Como información general, tu pedido ${customerData.order} está marcado como "${customerData.status}". ¿Te gustaría saber algo específico como la dirección de envío, fecha o estado actual?`;
            }
        }
        
        // Funciones auxiliares para agregar mensajes
        function addUserMessage(message) {
            addMessage('user', message);
        }
        
        function addBotMessage(message) {
            addMessage('bot', message);
        }
        
        function addMessage(sender, text) {
            const chatMessages = document.getElementById('chat-messages');
            const messageDiv = document.createElement('div');
            
            messageDiv.classList.add('message');
            if (sender === 'user') {
                messageDiv.classList.add('user-message');
                messageDiv.innerHTML = `
                    <div class="message-header">
                        <i class="fas fa-user"></i> Tú
                    </div>
                    ${text}
                `;
            } else {
                messageDiv.classList.add('bot-message');
                messageDiv.innerHTML = `
                    <div class="message-header">
                        <i class="fas fa-robot"></i> Asistente de Soporte
                    </div>
                    ${text}
                `;
            }
            
            chatMessages.appendChild(messageDiv);
        }
        
        // Inicializar la aplicación cuando se cargue la página
        document.addEventListener('DOMContentLoaded', loadCustomerInfo);
    </script>
</body>
</html>p