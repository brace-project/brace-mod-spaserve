import { API } from './generated-api';
import './app.css';

customElements.define('demo-app', class extends HTMLElement {
  connectedCallback() {
    const button = document.createElement('button');
    button.textContent = 'Load user 42';
    button.onclick = async () => {
      const user = await API.User.Get.request({ params: { userId: 42 }, query: { locale: 'de' } });
      this.textContent = user;
    };
    this.append(button);
  }
});
